<?php

namespace Nepicsoft\Osync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nepicsoft\Osync\Concerns\Syncable;
use Nepicsoft\Osync\Contracts\SyncCollection;
use Nepicsoft\Osync\Data\Change;
use Nepicsoft\Osync\Enums\Operation;

/**
 * A collection backed by one Eloquent model that uses [Syncable].
 *
 * Client data is written through [attributes] and server edits are journalled
 * through the model's [Syncable::toSyncPayload]; override both together when
 * the wire shape differs from the columns.
 *
 * Writes load every record of a batch in one query, then save each model, so
 * casts, mutators and model events behave as usual; a field the model changed
 * on save (a mutator, a `saving` hook) is sent back to devices. Set [bulk] for tables
 * whose payload maps straight onto columns: a batch then lands in a few
 * statements, skipping models, and needs a unique index on (scope, id).
 */
abstract class EloquentCollection implements SyncCollection
{
    protected bool $bulk = false;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    public function name(): string
    {
        return $this->instance()->syncCollection();
    }

    public function writable(): bool
    {
        return true;
    }

    public function apply(string $scope, array $changes): array
    {
        if ($this->bulk) {
            $this->applyBulk($scope, $changes);

            return [];
        }

        return $this->applyModels($scope, $changes);
    }

    /**
     * Limits [query] to the records of [scope]. Override, with [assignScope],
     * when ownership lives in another column — e.g. scope "user:42" stored as
     * user_id 42.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function scopeQuery(Builder $query, string $scope): Builder
    {
        return $query->where($this->instance()->syncScopeColumn(), $scope);
    }

    /** Marks [model] as belonging to [scope]. */
    protected function assignScope(Model $model, string $scope): void
    {
        $model->setAttribute($this->instance()->syncScopeColumn(), $scope);
    }

    /**
     * The model attributes for a validated client payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        return $data;
    }

    /**
     * @param  list<Change>  $changes
     * @return array<string, array<string, mixed>>
     */
    private function applyModels(string $scope, array $changes): array
    {
        $template = $this->instance();
        $idColumn = $template->syncIdColumn();
        $existing = $this->query($scope)
            ->whereIn($idColumn, array_map(fn (Change $c): string => $c->recordId, $changes))
            ->get()
            ->keyBy($idColumn);

        $stored = [];
        foreach ($changes as $change) {
            /** @var (Model&Syncable)|null $model */
            $model = $existing->get($change->recordId);

            if ($change->op === Operation::Delete) {
                $model?->delete();

                continue;
            }

            $model ??= $template->newInstance();
            $model->fill($this->attributes($change->data ?? []));
            $this->assignScope($model, $scope);
            $model->setAttribute($idColumn, $change->recordId);
            if ($this->usesSoftDeletes() && $model->exists && $model->trashed()) {
                $model->setAttribute($model->getDeletedAtColumn(), null);
            }
            $model->save();

            $payload = $model->toSyncPayload();
            if ($this->differs($change->data ?? [], $payload)) {
                $stored[$change->recordId] = $payload;
            }
        }

        return $stored;
    }

    /**
     * Whether the stored payload disagrees with what was pushed on any field
     * the push named. Loose on purpose: 27.7 and "27.7" are the same value
     * read back through a cast.
     *
     * @param  array<string, mixed>  $pushed
     * @param  array<string, mixed>  $stored
     */
    private function differs(array $pushed, array $stored): bool
    {
        foreach ($pushed as $key => $value) {
            if (array_key_exists($key, $stored) && $stored[$key] != $value) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<Change>  $changes */
    private function applyBulk(string $scope, array $changes): void
    {
        $template = $this->instance();
        $scopeColumn = $template->syncScopeColumn();
        $idColumn = $template->syncIdColumn();
        $now = Carbon::now();

        $upserts = [];
        $deletes = [];
        foreach ($changes as $change) {
            if ($change->op === Operation::Delete) {
                $deletes[] = $change->recordId;

                continue;
            }
            $row = array_map(
                fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $value,
                $this->attributes($change->data ?? []),
            );
            $row[$scopeColumn] = $scope;
            $row[$idColumn] = $change->recordId;
            if ($template->usesTimestamps()) {
                $row[$template->getCreatedAtColumn()] = $now;
                $row[$template->getUpdatedAtColumn()] = $now;
            }
            if ($this->usesSoftDeletes()) {
                $row[$template->getDeletedAtColumn()] = null;
            }
            $upserts[] = $row;
        }

        foreach ($this->sameShape($upserts) as $rows) {
            $update = array_values(array_diff(array_keys($rows[0]), [$scopeColumn, $idColumn, $template->getCreatedAtColumn()]));
            foreach (array_chunk($rows, 200) as $chunk) {
                $template->newQuery()->toBase()->upsert($chunk, [$scopeColumn, $idColumn], $update);
            }
        }

        foreach (array_chunk($deletes, 500) as $chunk) {
            $query = $template->newQuery()->toBase()->where($scopeColumn, $scope)->whereIn($idColumn, $chunk);
            $this->usesSoftDeletes()
                ? $query->update([$template->getDeletedAtColumn() => $now])
                : $query->delete();
        }
    }

    /**
     * Groups rows by their column set: one upsert statement needs every row
     * to name the same columns, and partial payloads must not null the rest.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    private function sameShape(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            ksort($row);
            $groups[implode(',', array_keys($row))][] = $row;
        }

        return array_values($groups);
    }

    /** @return Builder<Model> */
    private function query(string $scope): Builder
    {
        $query = $this->scopeQuery($this->instance()->newQuery(), $scope);

        return $this->usesSoftDeletes() ? $query->withTrashed() : $query;
    }

    /** @return Model&Syncable */
    private function instance(): Model
    {
        $class = $this->model();

        return new $class;
    }

    private function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->model()), true);
    }
}

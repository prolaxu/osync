<?php

namespace Nepicsoft\Osync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nepicsoft\Osync\Concerns\Syncable;
use Nepicsoft\Osync\Contracts\SyncCollection;

/**
 * A collection backed by one Eloquent model that uses [Syncable].
 *
 * Client data is written through [attributes] and server edits are journalled
 * through the model's [Syncable::toSyncPayload]; override both together when
 * the wire shape differs from the columns.
 */
abstract class EloquentCollection implements SyncCollection
{
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

    public function upsert(string $scope, string $recordId, array $data): void
    {
        $model = $this->find($scope, $recordId) ?? $this->instance()->newInstance();
        $model->fill($this->attributes($data));
        $model->setAttribute($model->syncScopeColumn(), $scope);
        $model->setAttribute($model->syncIdColumn(), $recordId);

        if ($this->usesSoftDeletes() && $model->exists && $model->trashed()) {
            $model->restore();
        }
        $model->save();
    }

    public function delete(string $scope, string $recordId): void
    {
        $this->find($scope, $recordId)?->delete();
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

    /** @return Model&Syncable */
    private function find(string $scope, string $recordId): ?Model
    {
        $model = $this->instance();
        /** @var Builder<Model> $query */
        $query = $model->newQuery();
        if ($this->usesSoftDeletes()) {
            $query->withTrashed();
        }

        return $query
            ->where($model->syncScopeColumn(), $scope)
            ->where($model->syncIdColumn(), $recordId)
            ->first();
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

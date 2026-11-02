<?php

namespace Nepicsoft\Osync\Concerns;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nepicsoft\Osync\Enums\Operation;
use Nepicsoft\Osync\Journal;

/**
 * Journals every save and delete of the model, so edits made on the server —
 * the website, the admin panel, a job — reach devices like any other change.
 *
 * A save that changes nothing devices see (a regenerated cache column, say)
 * is not journalled, so devices download nothing for it.
 *
 * Mass updates and deletes through the query builder fire no model events and
 * are not journalled; go through models, or record the change yourself with
 * [Journal::recordServerChange].
 */
trait Syncable
{
    /** The collection name devices sync this model under. */
    abstract public function syncCollection(): string;

    public static function bootSyncable(): void
    {
        static::creating(function (self $model): void {
            $column = $model->syncIdColumn();
            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, (string) Str::uuid());
            }
        });
        static::saved(fn (self $model) => $model->journalSyncChange(Operation::Upsert));
        static::deleted(fn (self $model) => $model->journalSyncChange(Operation::Delete));
    }

    public function syncScopeColumn(): string
    {
        return 'sync_scope';
    }

    public function syncIdColumn(): string
    {
        return 'sync_id';
    }

    /**
     * The scope this record syncs in. Override when ownership lives in another
     * column, e.g. `'user:'.$this->user_id`.
     */
    public function syncScope(): ?string
    {
        $scope = $this->getAttribute($this->syncScopeColumn());

        return blank($scope) ? null : (string) $scope;
    }

    /** @return array<string, mixed> what devices receive for this record */
    public function toSyncPayload(): array
    {
        return Arr::except($this->attributesToArray(), [
            $this->getKeyName(),
            $this->syncScopeColumn(),
            $this->syncIdColumn(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
            'deleted_at',
        ]);
    }

    /**
     * The columns whose change devices must hear about. Defaults to every
     * column except the bookkeeping ones; narrow it when [toSyncPayload] reads
     * only some.
     *
     * @return list<string>|null null for every column
     */
    public function syncedColumns(): ?array
    {
        return null;
    }

    private function journalSyncChange(Operation $op): void
    {
        $journal = Container::getInstance()->make(Journal::class);
        $scope = $this->syncScope();
        if ($journal->isQuiet() || $scope === null) {
            return;
        }
        if ($op === Operation::Upsert && ! $this->changedForDevices()) {
            return;
        }

        $journal->recordServerChange(
            scope: $scope,
            collection: $this->syncCollection(),
            recordId: (string) $this->getAttribute($this->syncIdColumn()),
            op: $op,
            data: $op === Operation::Upsert ? $this->toSyncPayload() : null,
        );
    }

    private function changedForDevices(): bool
    {
        if ($this->wasRecentlyCreated) {
            return true;
        }
        $changed = array_keys($this->getChanges());
        if (in_array('deleted_at', $changed, true)) {
            return true;
        }
        $ignored = [$this->getUpdatedAtColumn(), $this->getCreatedAtColumn()];
        $watched = $this->syncedColumns();

        foreach ($changed as $column) {
            if (in_array($column, $ignored, true)) {
                continue;
            }
            if ($watched === null || in_array($column, $watched, true)) {
                return true;
            }
        }

        return false;
    }
}

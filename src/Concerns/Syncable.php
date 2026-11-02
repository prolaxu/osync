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

    private function journalSyncChange(Operation $op): void
    {
        $journal = Container::getInstance()->make(Journal::class);
        $scope = $this->getAttribute($this->syncScopeColumn());
        if ($journal->isQuiet() || blank($scope)) {
            return;
        }

        $journal->recordServerChange(
            scope: (string) $scope,
            collection: $this->syncCollection(),
            recordId: (string) $this->getAttribute($this->syncIdColumn()),
            op: $op,
            data: $op === Operation::Upsert ? $this->toSyncPayload() : null,
        );
    }
}

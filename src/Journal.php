<?php

namespace Nepicsoft\Osync;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Nepicsoft\Osync\Data\Change;
use Nepicsoft\Osync\Enums\Operation;
use stdClass;

/**
 * The change journal every device pulls from.
 *
 * Each scope (one user's data, or "global") has a version counter. Every write
 * takes the scope's row lock, bumps the counter and stores the change under the
 * new version, so versions are gap-free in commit order: a reader that has
 * seen version N has seen everything committed before it, and a cursor never
 * skips a change.
 *
 * Only a record's latest change is kept, which makes a pull from version 0 a
 * complete snapshot. Deletes stay as tombstones until [prune] drops them past
 * the retention window; a device whose cursor is older than that is told to
 * reset.
 */
final class Journal
{
    private const SCOPES = 'osync_scopes';

    private const CHANGES = 'osync_changes';

    private bool $quiet = false;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $node,
    ) {}

    /**
     * Applies the [changes] that beat what their records hold, in one call to
     * [apply], and journals them under consecutive versions: one lock, one
     * read and one bulk write for the whole batch.
     *
     * A change equal to what the record holds is a resend and is skipped.
     * Returns, by record id, the newer change each losing record already held.
     *
     * When [apply] reports that it stored something other than what was
     * pushed, that payload is journalled under a fresh server stamp, so every
     * device — the sender too — converges on what the server holds.
     *
     * @param  list<Change>  $changes
     * @param  Closure(list<Change>): array<string, array<string, mixed>>  $apply
     * @return array<string, Change>
     */
    public function commitMany(string $scope, string $collection, array $changes, Closure $apply): array
    {
        if ($changes === []) {
            return [];
        }

        return $this->db->transaction(function () use ($scope, $collection, $changes, $apply): array {
            $version = (int) $this->lockScope($scope)->version;
            $held = $this->currentMany($scope, $collection, array_map(fn (Change $c): string => $c->recordId, $changes));

            $winners = [];
            $lost = [];
            foreach ($changes as $change) {
                $current = $winners[$change->recordId] ?? $held[$change->recordId] ?? null;
                if ($current !== null && $current->hlc->compare($change->hlc) >= 0) {
                    if ((string) $current->hlc !== (string) $change->hlc) {
                        $lost[$change->recordId] = $current;
                    }

                    continue;
                }
                $winners[$change->recordId] = $change;
                unset($lost[$change->recordId]);
            }

            if ($winners !== []) {
                $winners = array_values($winners);
                $stored = $this->quietly(fn (): array => $apply($winners));
                $now = Carbon::now()->getTimestampMs();
                $winners = array_map(
                    fn (Change $c): Change => isset($stored[$c->recordId])
                        ? new Change($c->recordId, Operation::Upsert, $stored[$c->recordId], Hlc::next($this->node, $now, $c->hlc))
                        : $c,
                    $winners,
                );
                $this->writeMany($scope, $collection, $winners, $version);
            }

            return $lost;
        });
    }

    /**
     * Journals a write made on the server (an admin edit, a web form, a job),
     * stamped to win over anything the record already holds.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function recordServerChange(string $scope, string $collection, string $recordId, Operation $op, ?array $data): Change
    {
        return $this->db->transaction(function () use ($scope, $collection, $recordId, $op, $data): Change {
            $version = (int) $this->lockScope($scope)->version;
            $current = $this->current($scope, $collection, $recordId);
            $change = new Change(
                recordId: $recordId,
                op: $op,
                data: $op === Operation::Upsert ? ($data ?? []) : null,
                hlc: Hlc::next($this->node, Carbon::now()->getTimestampMs(), $current?->hlc),
            );
            $this->writeMany($scope, $collection, [$change], $version);

            return $change;
        });
    }

    public function current(string $scope, string $collection, string $recordId): ?Change
    {
        $row = $this->db->table(self::CHANGES)
            ->where('scope', $scope)
            ->where('collection', $collection)
            ->where('record_id', $recordId)
            ->first();

        return $row === null ? null : $this->toChange($row);
    }

    /**
     * @param  list<string>  $recordIds
     * @return array<string, Change> by record id
     */
    public function currentMany(string $scope, string $collection, array $recordIds): array
    {
        $held = [];
        foreach (array_chunk(array_values(array_unique($recordIds)), 500) as $chunk) {
            $this->db->table(self::CHANGES)
                ->where('scope', $scope)
                ->where('collection', $collection)
                ->whereIn('record_id', $chunk)
                ->get(['record_id', 'op', 'data', 'hlc'])
                ->each(function (stdClass $row) use (&$held): void {
                    $held[$row->record_id] = $this->toChange($row);
                });
        }

        return $held;
    }

    /** @return list<array{version: int, change: Change}> oldest first */
    public function changesSince(string $scope, string $collection, int $cursor, int $limit): array
    {
        return $this->db->table(self::CHANGES)
            ->where('scope', $scope)
            ->where('collection', $collection)
            ->where('version', '>', $cursor)
            ->orderBy('version')
            ->limit($limit)
            ->get(['record_id', 'version', 'op', 'data', 'hlc'])
            ->map(fn (stdClass $row): array => ['version' => (int) $row->version, 'change' => $this->toChange($row)])
            ->values()
            ->all();
    }

    /** Versions at or below this may have lost tombstones; older cursors must reset. */
    public function compactedThrough(string $scope): int
    {
        return (int) ($this->db->table(self::SCOPES)->where('scope', $scope)->value('compacted_through') ?? 0);
    }

    /** Drops tombstones older than [before]. Returns how many went. */
    public function prune(Carbon $before): int
    {
        $pruned = 0;
        $this->db->table(self::CHANGES)
            ->where('op', Operation::Delete->value)
            ->where('created_at', '<', $before)
            ->select('scope')
            ->distinct()
            ->pluck('scope')
            ->each(function (string $scope) use ($before, &$pruned): void {
                $this->db->transaction(function () use ($scope, $before, &$pruned): void {
                    $this->lockScope($scope);
                    $tombstones = $this->db->table(self::CHANGES)
                        ->where('scope', $scope)
                        ->where('op', Operation::Delete->value)
                        ->where('created_at', '<', $before);
                    $through = (int) $tombstones->max('version');
                    $pruned += $tombstones->delete();
                    $this->db->table(self::SCOPES)
                        ->where('scope', $scope)
                        ->where('compacted_through', '<', $through)
                        ->update(['compacted_through' => $through]);
                });
            });

        return $pruned;
    }

    /**
     * Runs [callback] with model observers silenced, so a write the engine is
     * already journalling is not journalled a second time by [Syncable].
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function quietly(Closure $callback): mixed
    {
        $was = $this->quiet;
        $this->quiet = true;
        try {
            return $callback();
        } finally {
            $this->quiet = $was;
        }
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    private function lockScope(string $scope): stdClass
    {
        $this->db->table(self::SCOPES)->insertOrIgnore([
            'scope' => $scope,
            'version' => 0,
            'compacted_through' => 0,
        ]);

        /** @var stdClass $row */
        $row = $this->db->table(self::SCOPES)->where('scope', $scope)->lockForUpdate()->first();

        return $row;
    }

    /**
     * Stores [changes] under the versions after [afterVersion], replacing each
     * record's previous change, and moves the scope's counter to the last one.
     *
     * @param  list<Change>  $changes
     */
    private function writeMany(string $scope, string $collection, array $changes, int $afterVersion): void
    {
        $now = Carbon::now();
        $version = $afterVersion;
        $rows = [];
        foreach ($changes as $change) {
            $rows[] = [
                'scope' => $scope,
                'collection' => $collection,
                'record_id' => $change->recordId,
                'version' => ++$version,
                'op' => $change->op->value,
                'data' => $change->data === null ? null : json_encode($change->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'hlc' => (string) $change->hlc,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->db->table(self::CHANGES)->upsert(
                $chunk,
                ['scope', 'collection', 'record_id'],
                ['version', 'op', 'data', 'hlc', 'created_at'],
            );
        }
        $this->db->table(self::SCOPES)->where('scope', $scope)->update(['version' => $version]);
    }

    private function toChange(stdClass $row): Change
    {
        return new Change(
            recordId: $row->record_id,
            op: Operation::from($row->op),
            data: $row->data === null ? null : json_decode($row->data, true, flags: JSON_THROW_ON_ERROR),
            hlc: Hlc::parse($row->hlc),
        );
    }
}

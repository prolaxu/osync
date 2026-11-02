<?php

namespace Nepicsoft\Osync;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Nepicsoft\Osync\Contracts\SyncCollection;
use Nepicsoft\Osync\Data\Change;
use Nepicsoft\Osync\Data\CollectionPush;
use Nepicsoft\Osync\Data\CollectionResult;
use Nepicsoft\Osync\Data\Rejection;
use Nepicsoft\Osync\Enums\Operation;
use Nepicsoft\Osync\Enums\RejectionReason;
use Nepicsoft\Osync\Exceptions\ScopeDenied;

/**
 * One sync round for one user: apply what the device pushed, then hand back
 * everything in each collection's journal past the device's cursor.
 *
 * Pushing before pulling means a device's own accepted writes come back in the
 * same response with the stamps it sent; the client recognises them as already
 * applied. A write that lost to a newer one is rejected with the winner
 * attached, so the device converges even when its cursor is already past it.
 */
final readonly class SyncEngine
{
    public function __construct(
        private CollectionRegistry $collections,
        private Journal $journal,
        private ValidationFactory $validator,
        private int $pageSize,
    ) {}

    /**
     * @param  list<CollectionPush>  $pushes
     * @return array<string, CollectionResult>
     */
    public function sync(?Authenticatable $user, array $pushes): array
    {
        $results = [];
        foreach ($pushes as $push) {
            $collection = $this->collections->get($push->name);
            $scope = $collection->scope($user) ?? throw new ScopeDenied($push->name);

            $rejected = [];
            foreach ($push->changes as $change) {
                $rejection = $this->apply($collection, $scope, $change);
                if ($rejection !== null) {
                    $rejected[] = $rejection;
                }
            }

            $results[$push->name] = $this->pull($collection, $scope, $push->cursor, $rejected);
        }

        return $results;
    }

    private function apply(SyncCollection $collection, string $scope, Change $change): ?Rejection
    {
        if (! $collection->writable()) {
            return new Rejection($change->recordId, (string) $change->hlc, RejectionReason::ReadOnly);
        }

        if ($change->op === Operation::Upsert) {
            $validation = $this->validator->make($change->data ?? [], $collection->rules());
            if ($validation->fails()) {
                return new Rejection($change->recordId, (string) $change->hlc, RejectionReason::Invalid, errors: $validation->errors()->toArray());
            }
            $change = new Change($change->recordId, $change->op, $validation->validated(), $change->hlc);
        }

        $winner = $this->journal->commit($scope, $collection->name(), $change, fn () => match ($change->op) {
            Operation::Upsert => $collection->upsert($scope, $change->recordId, $change->data ?? []),
            Operation::Delete => $collection->delete($scope, $change->recordId),
        });

        return $winner === null
            ? null
            : new Rejection($change->recordId, (string) $change->hlc, RejectionReason::Stale, current: $winner);
    }

    /** @param  list<Rejection>  $rejected */
    private function pull(SyncCollection $collection, string $scope, int $cursor, array $rejected): CollectionResult
    {
        $reset = $cursor > 0 && $cursor < $this->journal->compactedThrough($scope);
        $from = $reset ? 0 : $cursor;

        $rows = $this->journal->changesSince($scope, $collection->name(), $from, $this->pageSize + 1);
        $hasMore = count($rows) > $this->pageSize;
        $rows = array_slice($rows, 0, $this->pageSize);

        return new CollectionResult(
            cursor: $rows === [] ? $from : $rows[array_key_last($rows)]['version'],
            changes: array_column($rows, 'change'),
            hasMore: $hasMore,
            reset: $reset,
            rejected: $rejected,
        );
    }
}

<?php

namespace Prolaxu\Osync;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Prolaxu\Osync\Contracts\SyncCollection;
use Prolaxu\Osync\Data\Change;
use Prolaxu\Osync\Data\CollectionPush;
use Prolaxu\Osync\Data\CollectionResult;
use Prolaxu\Osync\Data\Rejection;
use Prolaxu\Osync\Enums\Operation;
use Prolaxu\Osync\Enums\RejectionReason;
use Prolaxu\Osync\Exceptions\ScopeDenied;

/**
 * One sync round for one user: apply what the device pushed, then hand back
 * everything in each collection's journal past the device's cursor.
 *
 * A collection's push commits as one batch. A write that lost to a newer one
 * is rejected with the winner attached, so the device converges even when its
 * cursor is already past it. When the device names its node, the pull leaves
 * out changes that came from that node — it wrote them, so sending them back
 * would only cost bandwidth — except on a reset, after which the device has
 * dropped its synced records and needs every one. A reinstalled app has a new
 * node, so it gets its old changes back like anyone else's.
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
    public function sync(?Authenticatable $user, array $pushes, ?string $node = null): array
    {
        $results = [];
        $compacted = [];
        foreach ($pushes as $push) {
            $collection = $this->collections->get($push->name);
            $scope = $collection->scope($user) ?? throw new ScopeDenied($push->name);

            $rejected = $this->push($collection, $scope, $push->changes);
            $compacted[$scope] ??= $this->journal->compactedThrough($scope);
            $results[$push->name] = $this->pull($collection, $scope, $push->cursor, $compacted[$scope], $node, $rejected);
        }

        return $results;
    }

    /**
     * @param  list<Change>  $changes
     * @return list<Rejection>
     */
    private function push(SyncCollection $collection, string $scope, array $changes): array
    {
        if ($changes === []) {
            return [];
        }
        if (! $collection->writable()) {
            return array_map(fn (Change $c): Rejection => new Rejection($c->recordId, (string) $c->hlc, RejectionReason::ReadOnly), $changes);
        }

        $rejected = [];
        $accepted = [];
        $rules = $collection->rules();
        foreach ($changes as $change) {
            if ($change->op === Operation::Delete) {
                $accepted[] = $change;

                continue;
            }
            $validation = $this->validator->make($change->data ?? [], $rules);
            if ($validation->fails()) {
                $rejected[] = new Rejection($change->recordId, (string) $change->hlc, RejectionReason::Invalid, errors: $validation->errors()->toArray());

                continue;
            }
            $accepted[] = new Change($change->recordId, $change->op, $validation->validated(), $change->hlc);
        }

        $lost = $this->journal->commitMany(
            $scope,
            $collection->name(),
            $accepted,
            fn (array $winners) => $collection->apply($scope, $winners),
        );
        foreach ($accepted as $change) {
            $winner = $lost[$change->recordId] ?? null;
            if ($winner !== null) {
                $rejected[] = new Rejection($change->recordId, (string) $change->hlc, RejectionReason::Stale, current: $winner);
            }
        }

        return $rejected;
    }

    /** @param  list<Rejection>  $rejected */
    private function pull(SyncCollection $collection, string $scope, int $cursor, int $compactedThrough, ?string $node, array $rejected): CollectionResult
    {
        $reset = $cursor > 0 && $cursor < $compactedThrough;
        $from = $reset ? 0 : $cursor;

        $rows = $this->journal->changesSince($scope, $collection->name(), $from, $this->pageSize + 1);
        $hasMore = count($rows) > $this->pageSize;
        $rows = array_slice($rows, 0, $this->pageSize);

        $changes = [];
        $echo = ! $reset && $node !== null ? ':'.$node : null;
        foreach ($rows as ['change' => $change]) {
            if ($echo === null || ! str_ends_with((string) $change->hlc, $echo)) {
                $changes[] = $change;
            }
        }

        return new CollectionResult(
            cursor: $rows === [] ? $from : $rows[array_key_last($rows)]['version'],
            changes: $changes,
            hasMore: $hasMore,
            reset: $reset,
            rejected: $rejected,
        );
    }
}

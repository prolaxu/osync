<?php

namespace Prolaxu\Osync\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Prolaxu\Osync\Data\Change;

/**
 * A set of records that syncs as one stream, e.g. a user's saved charts or the
 * published mantras. The journal holds what devices see; the collection writes
 * accepted client changes into the app's own tables.
 */
interface SyncCollection
{
    /** The name clients address it by. */
    public function name(): string;

    /**
     * Whose records these are, e.g. "user:42", or "global" for shared content.
     * Null when this user may not use the collection at all.
     */
    public function scope(?Authenticatable $user): ?string;

    /** False for server-published content that devices only read. */
    public function writable(): bool;

    /** @return array<string, mixed> Laravel validation rules for an upsert's data */
    public function rules(): array;

    /**
     * Writes accepted changes into the app's tables: at most one per record,
     * upserts carrying validated data, all inside the journal's transaction.
     *
     * Returns the payload actually stored for any record the app changed on
     * the way in (a mutator, a `saving` hook); those reach every device, the
     * sender included, instead of what was pushed.
     *
     * @param  list<Change>  $changes
     * @return array<string, array<string, mixed>> by record id
     */
    public function apply(string $scope, array $changes): array;
}

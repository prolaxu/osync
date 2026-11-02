<?php

namespace Nepicsoft\Osync\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

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

    /** @param  array<string, mixed>  $data */
    public function upsert(string $scope, string $recordId, array $data): void;

    public function delete(string $scope, string $recordId): void;
}

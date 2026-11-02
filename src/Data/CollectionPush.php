<?php

namespace Prolaxu\Osync\Data;

/** What a client sends for one collection: where its pull stopped, and its local writes. */
final readonly class CollectionPush
{
    /** @param  list<Change>  $changes */
    public function __construct(
        public string $name,
        public int $cursor,
        public array $changes,
    ) {}

    /** @param  array{cursor?: int|null, changes?: list<array{id: string, op: string, hlc: string, data?: array<string, mixed>|null}>}  $data */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            cursor: (int) ($data['cursor'] ?? 0),
            changes: array_map(Change::fromArray(...), array_values($data['changes'] ?? [])),
        );
    }
}

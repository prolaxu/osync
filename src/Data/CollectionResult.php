<?php

namespace Nepicsoft\Osync\Data;

/** The server's answer for one collection. */
final readonly class CollectionResult
{
    /**
     * @param  list<Change>  $changes
     * @param  list<Rejection>  $rejected
     */
    public function __construct(
        public int $cursor,
        public array $changes,
        public bool $hasMore,
        public bool $reset,
        public array $rejected,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cursor' => $this->cursor,
            'reset' => $this->reset,
            'has_more' => $this->hasMore,
            'changes' => array_map(fn (Change $c): array => $c->toArray(), $this->changes),
            'rejected' => array_map(fn (Rejection $r): array => $r->toArray(), $this->rejected),
        ];
    }
}

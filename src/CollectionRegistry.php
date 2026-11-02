<?php

namespace Nepicsoft\Osync;

use Nepicsoft\Osync\Contracts\SyncCollection;
use Nepicsoft\Osync\Exceptions\UnknownCollection;

final class CollectionRegistry
{
    /** @var array<string, SyncCollection> */
    private array $collections = [];

    /** @param  iterable<SyncCollection>  $collections */
    public function __construct(iterable $collections = [])
    {
        foreach ($collections as $collection) {
            $this->register($collection);
        }
    }

    public function register(SyncCollection $collection): void
    {
        $this->collections[$collection->name()] = $collection;
    }

    public function get(string $name): SyncCollection
    {
        return $this->collections[$name] ?? throw new UnknownCollection($name);
    }

    public function has(string $name): bool
    {
        return isset($this->collections[$name]);
    }
}

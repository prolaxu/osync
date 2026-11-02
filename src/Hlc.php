<?php

namespace Nepicsoft\Osync;

use InvalidArgumentException;
use Stringable;

/**
 * A hybrid logical clock stamp: wall-clock milliseconds, a counter for events
 * within the same millisecond, and the node that issued it.
 *
 * The string form is fixed-width up to the node, so two stamps order the same
 * way as strings as they do as clocks — the database and the Dart client can
 * both compare them without parsing.
 */
final readonly class Hlc implements Stringable
{
    private const PATTERN = '/^(\d{15}):(\d{5}):([A-Za-z0-9_-]{1,64})$/';

    public const MAX_COUNTER = 99999;

    public function __construct(
        public int $millis,
        public int $counter,
        public string $node,
    ) {
        if ($millis < 0 || $counter < 0 || $counter > self::MAX_COUNTER || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $node) !== 1) {
            throw new InvalidArgumentException("Invalid HLC parts: {$millis}, {$counter}, {$node}");
        }
    }

    public static function parse(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw new InvalidArgumentException("Invalid HLC: {$value}");
        }

        return new self((int) $m[1], (int) $m[2], $m[3]);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /** A stamp for a new event on [node], later than both the wall clock and [after]. */
    public static function next(string $node, int $nowMillis, ?self $after = null): self
    {
        if ($after === null || $nowMillis > $after->millis) {
            return new self($nowMillis, 0, $node);
        }

        return $after->counter < self::MAX_COUNTER
            ? new self($after->millis, $after->counter + 1, $node)
            : new self($after->millis + 1, 0, $node);
    }

    public function compare(self $other): int
    {
        return strcmp((string) $this, (string) $other) <=> 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function __toString(): string
    {
        return sprintf('%015d:%05d:%s', $this->millis, $this->counter, $this->node);
    }
}

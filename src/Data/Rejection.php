<?php

namespace Nepicsoft\Osync\Data;

use Nepicsoft\Osync\Enums\RejectionReason;

final readonly class Rejection
{
    /** @param  array<string, list<string>>  $errors */
    public function __construct(
        public string $recordId,
        public string $hlc,
        public RejectionReason $reason,
        public ?Change $current = null,
        public array $errors = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->recordId,
            'hlc' => $this->hlc,
            'reason' => $this->reason->value,
            'current' => $this->current?->toArray(),
            'errors' => $this->errors === [] ? null : $this->errors,
        ], fn (mixed $value): bool => $value !== null);
    }
}

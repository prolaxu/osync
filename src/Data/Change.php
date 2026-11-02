<?php

namespace Prolaxu\Osync\Data;

use Prolaxu\Osync\Enums\Operation;
use Prolaxu\Osync\Hlc;

/** One write to one record: what the client pushes and what a pull returns. */
final readonly class Change
{
    /** @param  array<string, mixed>|null  $data  null for a delete */
    public function __construct(
        public string $recordId,
        public Operation $op,
        public ?array $data,
        public Hlc $hlc,
    ) {}

    /** @param  array{id: string, op: string, hlc: string, data?: array<string, mixed>|null}  $data */
    public static function fromArray(array $data): self
    {
        $op = Operation::from($data['op']);

        return new self(
            recordId: $data['id'],
            op: $op,
            data: $op === Operation::Upsert ? ($data['data'] ?? []) : null,
            hlc: Hlc::parse($data['hlc']),
        );
    }

    /** @return array{id: string, op: string, hlc: string, data?: array<string, mixed>} a delete carries no data key */
    public function toArray(): array
    {
        $array = ['id' => $this->recordId, 'op' => $this->op->value, 'hlc' => (string) $this->hlc];
        if ($this->data !== null) {
            $array['data'] = $this->data;
        }

        return $array;
    }
}

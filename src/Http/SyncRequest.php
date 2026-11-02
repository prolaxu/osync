<?php

namespace Prolaxu\Osync\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Prolaxu\Osync\Data\Change;
use Prolaxu\Osync\Data\CollectionPush;
use Prolaxu\Osync\Enums\Operation;
use Prolaxu\Osync\Hlc;
use Prolaxu\Osync\SyncProtocol;

/**
 * The sync envelope. Only the top level goes through Laravel's validator; the
 * changes are checked by hand in [pushes], because wildcard rules over a few
 * hundred changes cost more than the database work of the whole round.
 */
final class SyncRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'protocol' => ['required', 'integer', 'in:'.SyncProtocol::VERSION],
            'node' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'collections' => ['required', 'array', 'min:1', 'max:50'],
        ];
    }

    /** The device's HLC node, whose own changes its pulls can skip. */
    public function node(): ?string
    {
        return $this->validated('node');
    }

    /**
     * @return list<CollectionPush>
     *
     * @throws ValidationException
     */
    public function pushes(): array
    {
        $maxPush = (int) config('osync.max_push', 500);
        $pushes = [];
        foreach ($this->validated('collections') as $name => $push) {
            $path = "collections.{$name}";
            if (! is_string($name) || ! is_array($push)) {
                $this->fail($path, 'must be an object keyed by collection name');
            }
            $cursor = $push['cursor'] ?? 0;
            if (! is_int($cursor) || $cursor < 0) {
                $this->fail("{$path}.cursor", 'must be a non-negative integer');
            }
            $changes = $push['changes'] ?? [];
            if (! is_array($changes) || ! array_is_list($changes) || count($changes) > $maxPush) {
                $this->fail("{$path}.changes", "must be a list of at most {$maxPush} changes");
            }

            $pushes[] = new CollectionPush(
                $name,
                $cursor,
                array_map(fn (mixed $change, int $i): Change => $this->change($change, "{$path}.changes.{$i}"), $changes, array_keys($changes)),
            );
        }

        return $pushes;
    }

    private function change(mixed $change, string $path): Change
    {
        if (! is_array($change)) {
            $this->fail($path, 'must be an object');
        }
        $id = $change['id'] ?? null;
        $op = Operation::tryFrom(is_string($change['op'] ?? null) ? $change['op'] : '');
        $hlc = $change['hlc'] ?? null;
        $data = $change['data'] ?? null;

        match (true) {
            ! is_string($id) || $id === '' || strlen($id) > 191 => $this->fail("{$path}.id", 'must be a string of 1–191 characters'),
            $op === null => $this->fail("{$path}.op", 'must be upsert or delete'),
            ! is_string($hlc) || ! Hlc::isValid($hlc) => $this->fail("{$path}.hlc", 'must be a valid HLC stamp'),
            $data !== null && ! is_array($data) => $this->fail("{$path}.data", 'must be an object'),
            default => null,
        };

        return new Change($id, $op, $op === Operation::Upsert ? ($data ?? []) : null, Hlc::parse($hlc));
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => "The {$path} field {$message}."]);
    }
}

<?php

namespace Nepicsoft\Osync\Http;

use Illuminate\Foundation\Http\FormRequest;
use Nepicsoft\Osync\Data\CollectionPush;
use Nepicsoft\Osync\Enums\Operation;
use Nepicsoft\Osync\SyncProtocol;

final class SyncRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxPush = (int) config('osync.max_push', 500);

        return [
            'protocol' => ['required', 'integer', 'in:'.SyncProtocol::VERSION],
            'collections' => ['required', 'array', 'min:1', 'max:50'],
            'collections.*' => ['array'],
            'collections.*.cursor' => ['nullable', 'integer', 'min:0'],
            'collections.*.changes' => ['sometimes', 'array', "max:{$maxPush}"],
            'collections.*.changes.*.id' => ['required', 'string', 'max:191'],
            'collections.*.changes.*.op' => ['required', 'in:'.implode(',', array_column(Operation::cases(), 'value'))],
            'collections.*.changes.*.hlc' => ['required', 'string', 'regex:/^\d{15}:\d{5}:[A-Za-z0-9_-]{1,64}$/'],
            'collections.*.changes.*.data' => ['nullable', 'array'],
        ];
    }

    /** @return list<CollectionPush> */
    public function pushes(): array
    {
        /** @var array<string, array<string, mixed>> $collections */
        $collections = $this->validated('collections');

        return array_map(
            fn (string $name): CollectionPush => CollectionPush::fromArray($name, $collections[$name]),
            array_keys($collections),
        );
    }
}

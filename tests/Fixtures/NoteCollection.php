<?php

namespace Nepicsoft\Osync\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Nepicsoft\Osync\EloquentCollection;

class NoteCollection extends EloquentCollection
{
    protected function model(): string
    {
        return Note::class;
    }

    public function scope(?Authenticatable $user): ?string
    {
        return $user === null ? null : 'user:'.$user->getAuthIdentifier();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ];
    }
}

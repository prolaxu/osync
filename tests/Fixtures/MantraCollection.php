<?php

namespace Prolaxu\Osync\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Prolaxu\Osync\EloquentCollection;

final class MantraCollection extends EloquentCollection
{
    protected function model(): string
    {
        return Mantra::class;
    }

    public function scope(?Authenticatable $user): ?string
    {
        return 'global';
    }

    public function writable(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [];
    }
}

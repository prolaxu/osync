<?php

namespace Nepicsoft\Osync\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nepicsoft\Osync\EloquentCollection;

final class CardCollection extends EloquentCollection
{
    protected function model(): string
    {
        return Card::class;
    }

    public function scope(?Authenticatable $user): ?string
    {
        return $user === null ? null : 'user:'.$user->getAuthIdentifier();
    }

    public function rules(): array
    {
        return ['label' => ['required', 'string']];
    }

    protected function scopeQuery(Builder $query, string $scope): Builder
    {
        return $query->where('user_id', (int) substr($scope, 5));
    }

    protected function assignScope(Model $model, string $scope): void
    {
        $model->setAttribute('user_id', (int) substr($scope, 5));
    }
}

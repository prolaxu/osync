<?php

namespace Prolaxu\Osync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Prolaxu\Osync\Concerns\Syncable;

/** Owned through user_id rather than a scope column, as most apps store it. */
final class Card extends Model
{
    use Syncable;

    protected $fillable = ['label', 'cache', 'user_id', 'client_id'];

    public function syncCollection(): string
    {
        return 'cards';
    }

    public function syncIdColumn(): string
    {
        return 'client_id';
    }

    public function syncScope(): ?string
    {
        return $this->user_id === null ? null : 'user:'.$this->user_id;
    }

    public function syncedColumns(): array
    {
        return ['label'];
    }

    public function toSyncPayload(): array
    {
        return ['label' => $this->label];
    }
}

<?php

namespace Nepicsoft\Osync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nepicsoft\Osync\Concerns\Syncable;

final class Mantra extends Model
{
    use Syncable;

    protected $fillable = ['text'];

    protected $attributes = ['sync_scope' => 'global'];

    public function syncCollection(): string
    {
        return 'mantras';
    }
}

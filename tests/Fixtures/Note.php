<?php

namespace Nepicsoft\Osync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nepicsoft\Osync\Concerns\Syncable;

final class Note extends Model
{
    use SoftDeletes;
    use Syncable;

    protected $fillable = ['title', 'body', 'sync_scope', 'sync_id'];

    public function syncCollection(): string
    {
        return 'notes';
    }

    /** Collapses runs of spaces, so a test can see a stored value differ from the pushed one. */
    protected function title(): Attribute
    {
        return Attribute::set(fn (string $value): string => preg_replace('/\s+/', ' ', trim($value)));
    }
}

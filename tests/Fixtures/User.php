<?php

namespace Prolaxu\Osync\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    public $timestamps = false;

    protected $fillable = ['name'];
}

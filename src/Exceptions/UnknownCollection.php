<?php

namespace Prolaxu\Osync\Exceptions;

use RuntimeException;

final class UnknownCollection extends RuntimeException
{
    public function __construct(public readonly string $collection)
    {
        parent::__construct("Unknown sync collection [{$collection}].");
    }
}

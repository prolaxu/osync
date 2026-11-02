<?php

namespace Nepicsoft\Osync\Exceptions;

use RuntimeException;

final class ScopeDenied extends RuntimeException
{
    public function __construct(public readonly string $collection)
    {
        parent::__construct("Not allowed to sync [{$collection}].");
    }
}

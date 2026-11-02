<?php

namespace Prolaxu\Osync\Enums;

enum Operation: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
}

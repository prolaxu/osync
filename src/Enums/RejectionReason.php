<?php

namespace Nepicsoft\Osync\Enums;

enum RejectionReason: string
{
    /** A newer write already holds the record; the winning version is sent back. */
    case Stale = 'stale';
    case Invalid = 'invalid';
    case ReadOnly = 'read_only';
}

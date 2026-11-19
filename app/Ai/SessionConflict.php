<?php

namespace App\Ai;

use RuntimeException;

/** Thrown when another request saved the conversation after this one loaded it. */
final class SessionConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('SESSION_CONFLICT');
    }
}

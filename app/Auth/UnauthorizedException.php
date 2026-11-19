<?php

namespace App\Auth;

final class UnauthorizedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Unauthorized');
    }
}

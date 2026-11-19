<?php

namespace App\Support;

/** The request body does not have the shape the endpoint accepts. */
final class InvalidInput extends AppError
{
    public function __construct(string $path, string $problem)
    {
        parent::__construct("Invalid input: {$path} {$problem}");
    }
}

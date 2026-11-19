<?php

namespace App\Admin;

use App\Support\AppError;

final class PromptActivationError extends AppError
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

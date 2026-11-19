<?php

namespace App\Auth;

use App\Database\UserScope;

/**
 * The signed-in staff member of one request, with a database scope bound to
 * that user (row-level security applies). Set by the RequireStaff middleware
 * and read with $request->attributes->get('staff').
 */
final class Staff
{
    /** @param  array{required: bool, enrolled: bool}  $mfa */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email,
        public readonly UserScope $db,
        public readonly array $mfa,
    ) {}
}

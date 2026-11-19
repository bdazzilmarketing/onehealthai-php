<?php

namespace App\Auth;

use App\Database\Db;
use App\Database\Tx;

/**
 * Who is calling: the staff session from the HttpOnly session cookie, checked
 * against the database on every request (disabled accounts have no session).
 * Port of src/lib/auth/identity.server.ts.
 */
final class Identity
{
    public function __construct(private readonly Db $db, private readonly AuthService $auth) {}

    /**
     * @param  array<string, string>  $cookies  raw cookie values by name
     * @return array{id: string, email: ?string, twoFactorEnabled: bool, mfaRequired: bool}
     */
    public function authenticate(array $cookies): array
    {
        try {
            $user = $this->auth->sessionUser($cookies);
        } catch (\Throwable) {
            $user = null;
        }
        if ($user === null) {
            throw new UnauthorizedException;
        }
        $roles = $this->db->withServiceContext(fn (Tx $tx) => array_column($tx->select('select role::text as role from public.user_roles where user_id = ?', [$user['id']]), 'role'));

        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'twoFactorEnabled' => $user['twoFactorEnabled'],
            // One of the account's roles requires two-step sign-in (AUTH_MFA_REQUIRED_ROLES).
            'mfaRequired' => array_intersect($roles, $this->auth->config->mfaRequiredRoles) !== [],
        ];
    }

    /** True when the account's role requires two-step sign-in and it is not set up yet. */
    public static function requiresMfaSetup(array $user): bool
    {
        return $user['mfaRequired'] && ! $user['twoFactorEnabled'];
    }
}

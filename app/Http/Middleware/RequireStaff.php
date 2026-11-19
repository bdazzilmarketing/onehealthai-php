<?php

namespace App\Http\Middleware;

use App\Auth\Identity;
use App\Auth\Staff;
use App\Auth\UnauthorizedException;
use App\Database\Db;
use App\Database\UserScope;
use App\Http\Support\RawCookies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards for signed-in staff (port of src/lib/auth/middleware.ts).
 *
 *   staff:session  any signed-in account, including one that still has to set
 *                  up two-step sign-in. Only for the session summary the
 *                  portal needs to show that step.
 *   staff          signed-in staff; accounts whose role requires two-step
 *                  sign-in must have it set up (MFA_SETUP_REQUIRED otherwise).
 */
final class RequireStaff
{
    public function __construct(private readonly Identity $identity, private readonly Db $db) {}

    public function handle(Request $request, Closure $next, string $level = 'auth'): Response
    {
        try {
            $user = $this->identity->authenticate(RawCookies::from($request));
        } catch (UnauthorizedException) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ($level !== 'session' && Identity::requiresMfaSetup($user)) {
            return response()->json(['message' => 'MFA_SETUP_REQUIRED'], 403);
        }
        $request->attributes->set('staff', new Staff(
            $user['id'],
            $user['email'],
            new UserScope($this->db, $user['id']),
            ['required' => $user['mfaRequired'], 'enrolled' => $user['twoFactorEnabled']],
        ));

        return $next($request);
    }
}

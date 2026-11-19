<?php

namespace App\Auth;

use App\Database\Db;
use App\Database\Tx;

/**
 * Staff authentication behind /api/auth/* (port of src/lib/auth: the Better
 * Auth configuration and its request handler). Same endpoints, same cookies,
 * same tables and stored formats as the original application.
 *
 * - Sessions live in auth.sessions; the browser holds only an HttpOnly cookie.
 * - Every query runs as role onehealth_auth, which can touch the auth tables
 *   and nothing else.
 * - Sign-up and self-service password reset do not exist: accounts are created
 *   by a Super Admin, and activation/reset links are issued server-side.
 * - Authorization is separate: roles stay in public.user_roles and are checked
 *   by the SQL functions and row-level security policies.
 */
final class AuthService
{
    private const RATE_LIMITS = [
        '/sign-in/email' => [60, 5],
        '/two-factor/verify-totp' => [60, 5],
        '/two-factor/verify-backup-code' => [60, 5],
        '/reset-password' => [60, 5],
        '/change-password' => [60, 5],
    ];

    private const TWO_FACTOR_ATTEMPTS = 5;

    private const LOCKOUT_FAILURES = 10;

    private const LOCKOUT_SECONDS = 900;

    private readonly PasswordHasher $passwords;

    public function __construct(private readonly Db $db, public readonly AuthConfig $config)
    {
        $this->passwords = new PasswordHasher($config->passwordAlgorithm);
    }

    /**
     * @param  string  $path  the part after /api/auth, e.g. "/sign-in/email"
     * @param  array<string, string>  $cookies  raw (still URL-encoded) cookie values by name
     * @param  ?string  $clientIp  from the trusted resolver only, never a client header
     */
    public function handle(string $method, string $path, ?string $origin, array $cookies, mixed $body, ?string $clientIp, ?string $userAgent): AuthResponse
    {
        $path = rtrim($path, '/');
        if (! in_array("{$method} {$path}", AuthConfig::ALLOWED_ENDPOINTS, true)) {
            return new AuthResponse(404, null, text: 'Not found');
        }
        // Every state-changing call must come from the portal's own origin (sign-in
        // CSRF included), whether or not a session cookie is present.
        if ($method !== 'GET' && ! in_array($origin ?? '', $this->config->origins, true)) {
            return new AuthResponse(403, null, text: 'Forbidden');
        }
        $retryAfter = $this->consumeRateLimit($clientIp, $path);
        if ($retryAfter !== null) {
            return new AuthResponse(429, ['message' => 'Too many requests. Please try again later.'], headers: ['X-Retry-After' => (string) $retryAfter]);
        }

        $request = new AuthRequest($cookies, is_array($body) ? $body : [], $clientIp, $userAgent);
        try {
            $json = match ($path) {
                '/sign-in/email' => $this->signIn($request),
                '/sign-out' => $this->signOut($request),
                '/get-session' => $this->getSession($request),
                '/reset-password' => $this->resetPassword($request),
                '/change-password' => $this->changePassword($request),
                '/two-factor/enable' => $this->enableTwoFactor($request),
                '/two-factor/get-totp-uri' => $this->getTotpUri($request),
                '/two-factor/verify-totp' => $this->verifyTotp($request),
                '/two-factor/verify-backup-code' => $this->verifyBackupCode($request),
            };

            return new AuthResponse(200, $json, $request->setCookies, $request->headers);
        } catch (AuthException $error) {
            return new AuthResponse($error->status, $error->body(), $request->setCookies, $request->headers);
        }
    }

    // ------------------------------------------------------------ server API

    /**
     * The signed-in user for these cookies, or null. Read from the database on
     * every request, so disabling an account takes effect at once.
     *
     * @param  array<string, string>  $cookies
     * @return array{id: string, email: string, twoFactorEnabled: bool}|null
     */
    public function sessionUser(array $cookies): ?array
    {
        $found = $this->findSession(new AuthRequest($cookies, [], null, null));
        if ($found === null || $found['user']['disabled_at'] !== null) {
            return null;
        }

        return ['id' => $found['user']['id'], 'email' => $found['user']['email'], 'twoFactorEnabled' => (bool) $found['user']['two_factor_enabled']];
    }

    /**
     * Issues a single-use activation or password link for an existing account
     * and returns its URL. The token is stored hashed; the caller delivers the
     * URL and must never log or return it to a browser.
     *
     * @return array{url: string, validFor: string}
     */
    public function issueAccountLink(string $userId, string $kind, string $origin): array
    {
        $validity = AuthConfig::LINK_VALIDITY[$kind];
        $token = Crypto::base64Url(random_bytes(32));
        $this->db->withAuthContext(fn (Tx $tx) => $this->createVerification($tx, "reset-password:{$token}", $userId, $validity['seconds']));
        $page = $kind === 'invite' ? '/admin-activate' : '/admin-reset-password';

        return ['url' => "{$origin}{$page}?token=".rawurlencode($token), 'validFor' => $validity['words']];
    }

    /** Sets an initial password (Super Admin "create with temporary password"). */
    public function setInitialPassword(string $userId, string $password): void
    {
        if (mb_strlen($password) < AuthConfig::MIN_PASSWORD_LENGTH) {
            throw new \RuntimeException('Password too short');
        }
        $hash = $this->passwords->hash($password);
        $this->db->withAuthContext(fn (Tx $tx) => $tx->execute(
            "insert into auth.accounts (user_id, provider_id, account_id, password) values (?, 'credential', ?, ?)",
            [$userId, $userId, $hash],
        ));
    }

    // ------------------------------------------------------------- endpoints

    private function signIn(AuthRequest $request): array
    {
        $email = $request->string('email');
        $password = $request->string('password');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw AuthException::code(400, 'INVALID_EMAIL');
        }
        $this->assertNotTooLong($password);

        $row = $this->db->withAuthContext(fn (Tx $tx) => $tx->first(
            "select u.*, a.password from auth.users u
             left join auth.accounts a on a.user_id = u.id and a.provider_id = 'credential' and a.account_id = u.id::text
             where u.email = ?",
            [strtolower($email)],
        ));
        if ($row === null || $row['password'] === null) {
            // Same work as a real check, so timing does not reveal which accounts exist.
            $this->passwords->hash($password);
            throw AuthException::code(401, 'INVALID_EMAIL_OR_PASSWORD');
        }
        if (! $this->passwords->verify($row['password'], $password)) {
            throw AuthException::code(401, 'INVALID_EMAIL_OR_PASSWORD');
        }
        // A disabled account can never obtain a session, whatever the sign-in path.
        if ($row['disabled_at'] !== null) {
            throw new AuthException(403, null, 'This account has been disabled');
        }
        if ($this->passwords->needsRehash($row['password'])) {
            $hash = $this->passwords->hash($password);
            $this->db->withAuthContext(fn (Tx $tx) => $tx->execute(
                "update auth.accounts set password = ?, updated_at = now() where user_id = ? and provider_id = 'credential' and password = ?",
                [$hash, $row['id'], $row['password']],
            ));
        }

        if ($row['two_factor_enabled']) {
            return $this->beginTwoFactorChallenge($request, $row);
        }

        $session = $this->db->withAuthContext(fn (Tx $tx) => $this->createSession($tx, $row['id'], $request->clientIp, $request->userAgent));
        $this->setSessionCookie($request, $session['token']);

        return ['redirect' => false, 'token' => $session['token'], 'user' => $this->userOutput($row)];
    }

    /** Password accepted, second step pending: no session yet, only a short-lived challenge. */
    private function beginTwoFactorChallenge(AuthRequest $request, array $user): array
    {
        $identifier = '2fa-'.Crypto::randomString(20);
        $methods = $this->db->withAuthContext(function (Tx $tx) use ($identifier, $user) {
            $this->createVerification($tx, $identifier, $user['id'], AuthConfig::TWO_FACTOR_COOKIE_SECONDS);
            $this->createVerification($tx, "2fa-attempts-{$identifier}", '0', AuthConfig::TWO_FACTOR_COOKIE_SECONDS);
            $twoFactor = $tx->first('select verified from auth.two_factors where user_id = ?', [$user['id']]);

            return $twoFactor !== null && $twoFactor['verified'] !== false ? ['totp'] : [];
        });
        $request->setCookies[] = $this->config->expiredCookie('session_token');
        $request->setCookies[] = $this->config->expiredCookie('session_data');
        $request->setCookies[] = $this->config->signedCookie('two_factor', $identifier, AuthConfig::TWO_FACTOR_COOKIE_SECONDS);

        return ['twoFactorRedirect' => true, 'twoFactorMethods' => $methods];
    }

    private function signOut(AuthRequest $request): array
    {
        $token = $this->cookie($request, 'session_token');
        if ($token !== null) {
            $this->db->withAuthContext(fn (Tx $tx) => $tx->execute('delete from auth.sessions where token = ?', [$token]));
        }
        $this->deleteSessionCookies($request);

        return ['success' => true];
    }

    private function getSession(AuthRequest $request): ?array
    {
        $request->headers['Cache-Control'] = 'no-store';
        $request->headers['Pragma'] = 'no-cache';
        $found = $this->findSession($request, refresh: true);
        if ($found === null) {
            return null;
        }

        return ['session' => $this->sessionOutput($found['session']), 'user' => $this->userOutput($found['user'])];
    }

    private function resetPassword(AuthRequest $request): array
    {
        $token = $request->string('token', required: false);
        $newPassword = $request->string('newPassword');
        if ($token === '') {
            throw AuthException::code(400, 'INVALID_TOKEN');
        }
        $this->assertNotTooShort($newPassword);
        $this->assertNotTooLong($newPassword);
        $identifier = "reset-password:{$token}";

        $verification = $this->db->withAuthContext(fn (Tx $tx) => $this->findVerification($tx, $identifier));
        if ($verification === null || $verification['expired']) {
            throw AuthException::code(400, 'INVALID_TOKEN');
        }
        $userId = $verification['value'];
        $hash = $this->passwords->hash($newPassword);

        $this->db->withAuthContext(function (Tx $tx) use ($identifier, $userId, $hash) {
            if ($tx->first('select id from auth.users where id = ?', [$userId]) === null) {
                throw AuthException::code(400, 'USER_NOT_FOUND');
            }
            // Single use: whoever deletes the row wins.
            if ($this->consumeVerification($tx, $identifier) === null) {
                throw AuthException::code(400, 'INVALID_TOKEN');
            }
            $tx->execute('delete from auth.sessions where user_id = ?', [$userId]);
            $updated = $tx->execute("update auth.accounts set password = ?, updated_at = now() where user_id = ? and provider_id = 'credential'", [$hash, $userId]);
            if ($updated === 0) {
                $tx->execute("insert into auth.accounts (user_id, provider_id, account_id, password) values (?, 'credential', ?, ?)", [$userId, $userId, $hash]);
            }
            // Choosing a password through an emailed link proves the address.
            $tx->execute('update auth.users set email_verified = true, updated_at = now() where id = ?', [$userId]);
        });

        return ['status' => true];
    }

    private function changePassword(AuthRequest $request): array
    {
        $found = $this->requireSession($request);
        $newPassword = $request->string('newPassword');
        $currentPassword = $request->string('currentPassword');
        $revoke = ($request->body['revokeOtherSessions'] ?? false) === true;
        $this->assertNotTooShort($newPassword);
        $this->assertNotTooLong($newPassword);
        $this->assertNotTooLong($currentPassword);
        $userId = $found['user']['id'];

        $account = $this->db->withAuthContext(fn (Tx $tx) => $tx->first("select id, password from auth.accounts where user_id = ? and provider_id = 'credential'", [$userId]));
        if ($account === null || $account['password'] === null) {
            throw AuthException::code(400, 'CREDENTIAL_ACCOUNT_NOT_FOUND');
        }
        $hash = $this->passwords->hash($newPassword);
        if (! $this->passwords->verify($account['password'], $currentPassword)) {
            throw AuthException::code(400, 'INVALID_PASSWORD');
        }
        $session = $this->db->withAuthContext(function (Tx $tx) use ($revoke, $userId, $account, $hash, $request) {
            if ($revoke) {
                $tx->execute('delete from auth.sessions where user_id = ?', [$userId]);
            }
            $tx->execute('update auth.accounts set password = ?, updated_at = now() where id = ?', [$hash, $account['id']]);

            return $revoke ? $this->createSession($tx, $userId, $request->clientIp, $request->userAgent) : null;
        });
        if ($session !== null) {
            $this->setSessionCookie($request, $session['token']);
        }

        return ['token' => $session['token'] ?? null, 'user' => $this->userOutput($found['user'])];
    }

    private function enableTwoFactor(AuthRequest $request): array
    {
        $found = $this->requireSession($request);
        $password = $request->string('password');
        $method = $request->body['method'] ?? 'totp';
        if ($method !== 'totp') {
            // Only authenticator apps are offered; emailed codes are not configured.
            throw new AuthException(400, $method === 'otp' ? 'OTP_NOT_CONFIGURED' : 'VALIDATION_ERROR', $method === 'otp' ? 'OTP is not available' : 'Invalid body parameters');
        }
        $user = $found['user'];
        if (! $this->validatePassword($user['id'], $password)) {
            throw AuthException::code(400, 'INVALID_PASSWORD');
        }

        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = Crypto::randomString(10, 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ');
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
        }
        $secret = Crypto::randomString(32);
        $encryptedSecret = Crypto::encrypt($this->config->secret, $secret);
        $encryptedCodes = Crypto::encrypt($this->config->secret, json_encode($codes));

        $this->db->withAuthContext(function (Tx $tx) use ($user, $encryptedSecret, $encryptedCodes) {
            $existing = $tx->first('select id, verified from auth.two_factors where user_id = ?', [$user['id']]);
            if ($existing !== null && $existing['verified'] !== false) {
                throw AuthException::code(400, 'TOTP_ALREADY_ENABLED');
            }
            // Not on until a code from the app is verified.
            if ($existing !== null) {
                $tx->execute('update auth.two_factors set secret = ?, backup_codes = ?, verified = false where id = ?', [$encryptedSecret, $encryptedCodes, $existing['id']]);
            } else {
                $tx->execute('insert into auth.two_factors (user_id, secret, backup_codes, verified) values (?, ?, ?, false)', [$user['id'], $encryptedSecret, $encryptedCodes]);
            }
        });

        return ['method' => 'totp', 'totpURI' => Totp::uri($secret, AuthConfig::APP_NAME, $user['email']), 'backupCodes' => $codes];
    }

    private function getTotpUri(AuthRequest $request): array
    {
        $found = $this->requireSession($request);
        $password = $request->string('password');
        $user = $found['user'];
        $twoFactor = $this->db->withAuthContext(fn (Tx $tx) => $tx->first('select secret from auth.two_factors where user_id = ?', [$user['id']]));
        if ($twoFactor === null) {
            throw AuthException::code(400, 'TOTP_NOT_ENABLED');
        }
        $secret = Crypto::decrypt($this->config->secret, $twoFactor['secret']);
        if ($password === '' || ! $this->validatePassword($user['id'], $password)) {
            throw AuthException::code(400, 'INVALID_PASSWORD');
        }

        return ['totpURI' => Totp::uri($secret, AuthConfig::APP_NAME, $user['email'])];
    }

    private function verifyTotp(AuthRequest $request): array
    {
        $code = $request->string('code');
        $challenge = $this->twoFactorChallenge($request);
        $user = $challenge['user'];
        $isSignIn = $challenge['session'] === null;

        $twoFactor = $this->db->withAuthContext(fn (Tx $tx) => $tx->first('select * from auth.two_factors where user_id = ?', [$user['id']]));
        if ($twoFactor === null || ($isSignIn && $twoFactor['verified'] === false)) {
            throw AuthException::code(400, 'TOTP_NOT_ENABLED');
        }
        $attempts = null;
        if ($isSignIn) {
            $this->assertNotLocked($twoFactor);
            $attempts = $this->beginAttempt($request, $challenge);
        }
        try {
            $valid = Totp::verify(Crypto::decrypt($this->config->secret, $twoFactor['secret']), $code);
        } catch (\Throwable $error) {
            $this->rearmAttempts($challenge, $attempts);
            throw $error;
        }
        if (! $valid) {
            $this->rearmAttempts($challenge, $attempts === null ? null : $attempts + 1);
            if ($isSignIn) {
                $this->recordFailure($twoFactor['id']);
            }
            throw AuthException::code(401, 'INVALID_CODE');
        }
        if ($isSignIn) {
            $this->resetFailures($twoFactor['id']);
        }

        if ($twoFactor['verified'] !== true) {
            // First verified code: two-step sign-in is now on, under a fresh session.
            $this->db->withAuthContext(function (Tx $tx) use ($user, $challenge, $twoFactor, $request) {
                if (! $user['two_factor_enabled']) {
                    $tx->execute('update auth.users set two_factor_enabled = true, updated_at = now() where id = ?', [$user['id']]);
                    $old = $challenge['session'];
                    $session = $this->createSession($tx, $user['id'], $old['ip_address'], $old['user_agent']);
                    $this->setSessionCookie($request, $session['token']);
                    $tx->execute('delete from auth.sessions where token = ?', [$old['token']]);
                }
                $tx->execute('update auth.two_factors set verified = true where id = ?', [$twoFactor['id']]);
            });
        }

        return $this->completeChallenge($request, $challenge);
    }

    private function verifyBackupCode(AuthRequest $request): array
    {
        $code = $request->string('code');
        $challenge = $this->twoFactorChallenge($request);
        $user = $challenge['user'];
        $isSignIn = $challenge['session'] === null;

        $twoFactor = $this->db->withAuthContext(fn (Tx $tx) => $tx->first('select * from auth.two_factors where user_id = ?', [$user['id']]));
        if ($twoFactor === null) {
            throw AuthException::code(400, 'BACKUP_CODES_NOT_ENABLED');
        }
        $attempts = null;
        if ($isSignIn) {
            $this->assertNotLocked($twoFactor);
            $attempts = $this->beginAttempt($request, $challenge);
        }
        try {
            $codes = json_decode(Crypto::decrypt($this->config->secret, $twoFactor['backup_codes']), true);
        } catch (\Throwable $error) {
            $this->rearmAttempts($challenge, $attempts);
            throw $error;
        }
        if (! is_array($codes) || ! in_array($code, $codes, true)) {
            $this->rearmAttempts($challenge, $attempts === null ? null : $attempts + 1);
            if ($isSignIn) {
                $this->recordFailure($twoFactor['id']);
            }
            throw AuthException::code(401, 'INVALID_BACKUP_CODE');
        }
        $remaining = Crypto::encrypt($this->config->secret, json_encode(array_values(array_filter($codes, fn ($c) => $c !== $code))));
        // Compare-and-set on the stored list, so one code cannot be spent twice at once.
        $spent = $this->db->withAuthContext(fn (Tx $tx) => $tx->execute(
            'update auth.two_factors set backup_codes = ? where id = ? and backup_codes = ?',
            [$remaining, $twoFactor['id'], $twoFactor['backup_codes']],
        ));
        if ($spent !== 1) {
            throw new AuthException(409, null, 'Failed to verify backup code. Please try again.');
        }
        if ($isSignIn) {
            $this->resetFailures($twoFactor['id']);
        }

        return $this->completeChallenge($request, $challenge);
    }

    // ------------------------------------------------------- two-step support

    /**
     * Who is answering the second step: a signed-in user (setting it up), or
     * the holder of a sign-in challenge cookie.
     *
     * @return array{user: array, session: ?array, identifier: ?string, expiresIn: int}
     */
    private function twoFactorChallenge(AuthRequest $request): array
    {
        $found = $this->findSession($request);
        if ($found !== null) {
            return ['user' => $found['user'], 'session' => $found['session'], 'identifier' => null, 'expiresIn' => 0];
        }
        $identifier = $this->cookie($request, 'two_factor');
        if ($identifier === null) {
            throw AuthException::code(401, 'INVALID_TWO_FACTOR_COOKIE');
        }
        $row = $this->db->withAuthContext(function (Tx $tx) use ($identifier) {
            $verification = $this->findVerification($tx, $identifier);
            if ($verification === null) {
                return null;
            }
            $user = $tx->first('select * from auth.users where id = ?', [$verification['value']]);

            return $user === null ? null : ['user' => $user, 'expiresIn' => $verification['expires_in']];
        });
        if ($row === null) {
            throw AuthException::code(401, 'INVALID_TWO_FACTOR_COOKIE');
        }

        return ['user' => $row['user'], 'session' => null, 'identifier' => $identifier, 'expiresIn' => $row['expiresIn']];
    }

    /** Takes the attempt counter of a challenge; the caller re-arms it afterwards. */
    private function beginAttempt(AuthRequest $request, array $challenge): int
    {
        $identifier = $challenge['identifier'];
        $consumed = $this->db->withAuthContext(fn (Tx $tx) => $this->consumeVerification($tx, "2fa-attempts-{$identifier}"));
        if ($consumed === null) {
            throw AuthException::code(401, 'INVALID_TWO_FACTOR_COOKIE');
        }
        $attempts = ctype_digit($consumed['value']) ? (int) $consumed['value'] : self::TWO_FACTOR_ATTEMPTS;
        if ($attempts >= self::TWO_FACTOR_ATTEMPTS) {
            $this->db->withAuthContext(fn (Tx $tx) => $this->consumeVerification($tx, $identifier));
            $request->setCookies[] = $this->config->expiredCookie('two_factor');
            throw AuthException::code(400, 'TOO_MANY_ATTEMPTS_REQUEST_NEW_CODE');
        }

        return $attempts;
    }

    private function rearmAttempts(array $challenge, ?int $count): void
    {
        if ($count === null || $challenge['identifier'] === null) {
            return;
        }
        $this->db->withAuthContext(fn (Tx $tx) => $this->createVerification($tx, "2fa-attempts-{$challenge['identifier']}", (string) $count, max(1, $challenge['expiresIn'])));
    }

    private function completeChallenge(AuthRequest $request, array $challenge): array
    {
        $user = $challenge['user'];
        if ($challenge['session'] !== null) {
            return ['token' => $challenge['session']['token'], 'user' => $this->userOutput($user)];
        }
        $session = $this->db->withAuthContext(function (Tx $tx) use ($challenge, $user, $request) {
            $consumed = $this->consumeVerification($tx, $challenge['identifier']);
            if ($consumed === null || $consumed['value'] !== $user['id']) {
                return null;
            }
            if ($tx->value('select disabled_at from auth.users where id = ?', [$user['id']]) !== null) {
                throw new AuthException(403, null, 'This account has been disabled');
            }

            return $this->createSession($tx, $user['id'], $request->clientIp, $request->userAgent);
        });
        $request->setCookies[] = $this->config->expiredCookie('two_factor');
        if ($session === null) {
            throw AuthException::code(401, 'INVALID_TWO_FACTOR_COOKIE');
        }
        $this->setSessionCookie($request, $session['token']);

        return ['token' => $session['token'], 'user' => $this->userOutput($user)];
    }

    private function assertNotLocked(array $twoFactor): void
    {
        if ($twoFactor['locked_until'] === null) {
            return;
        }
        $locked = $this->db->withAuthContext(function (Tx $tx) use ($twoFactor) {
            if ($tx->value('select locked_until > now() from auth.two_factors where id = ?', [$twoFactor['id']]) === true) {
                return true;
            }
            $tx->execute('update auth.two_factors set failed_verification_count = 0, locked_until = null where id = ? and locked_until <= now()', [$twoFactor['id']]);

            return false;
        });
        if ($locked) {
            throw AuthException::code(429, 'ACCOUNT_TEMPORARILY_LOCKED');
        }
    }

    private function recordFailure(string $twoFactorId): void
    {
        $this->db->withAuthContext(function (Tx $tx) use ($twoFactorId) {
            $count = $tx->value('update auth.two_factors set failed_verification_count = coalesce(failed_verification_count, 0) + 1 where id = ? returning failed_verification_count', [$twoFactorId]);
            if ((int) $count >= self::LOCKOUT_FAILURES) {
                $tx->execute('update auth.two_factors set locked_until = now() + make_interval(secs => ?) where id = ? and failed_verification_count >= ?', [self::LOCKOUT_SECONDS, $twoFactorId, self::LOCKOUT_FAILURES]);
            }
        });
    }

    private function resetFailures(string $twoFactorId): void
    {
        $this->db->withAuthContext(fn (Tx $tx) => $tx->execute('update auth.two_factors set failed_verification_count = 0, locked_until = null where id = ?', [$twoFactorId]));
    }

    // -------------------------------------------------------------- sessions

    /** @return array{session: array, user: array}|null */
    private function findSession(AuthRequest $request, bool $refresh = false): ?array
    {
        $token = $this->cookie($request, 'session_token');
        if ($token === null) {
            return null;
        }
        $rememberOnly = $this->cookie($request, 'dont_remember') !== null;

        $result = $this->db->withAuthContext(function (Tx $tx) use ($token, $refresh, $rememberOnly) {
            $session = $tx->first('select *, expires_at < now() as expired, expires_at - make_interval(secs => ?) + make_interval(secs => ?) <= now() as stale from auth.sessions where token = ?', [AuthConfig::SESSION_SECONDS, AuthConfig::SESSION_UPDATE_AGE, $token]);
            if ($session === null) {
                return ['gone' => true];
            }
            if ($session['expired']) {
                $tx->execute('delete from auth.sessions where token = ?', [$token]);

                return ['gone' => true];
            }
            $user = $tx->first('select * from auth.users where id = ?', [$session['user_id']]);
            if ($user === null) {
                return ['gone' => true];
            }
            $refreshed = false;
            if ($refresh && ! $rememberOnly && $session['stale']) {
                $session = $tx->first('update auth.sessions set expires_at = now() + make_interval(secs => ?), updated_at = now() where token = ? returning *', [AuthConfig::SESSION_SECONDS, $token]) ?? $session;
                $refreshed = true;
            }

            return ['session' => $session, 'user' => $user, 'refreshed' => $refreshed];
        });

        if (isset($result['gone'])) {
            if ($refresh) {
                $this->deleteSessionCookies($request);
            }

            return null;
        }
        if ($result['refreshed']) {
            $this->setSessionCookie($request, $token);
        }

        return ['session' => $result['session'], 'user' => $result['user']];
    }

    /** @return array{session: array, user: array} */
    private function requireSession(AuthRequest $request): array
    {
        return $this->findSession($request) ?? throw AuthException::code(401, 'UNAUTHORIZED');
    }

    private function createSession(Tx $tx, string $userId, ?string $ip, ?string $userAgent): array
    {
        return $tx->first(
            'insert into auth.sessions (user_id, token, expires_at, ip_address, user_agent) values (?, ?, now() + make_interval(secs => ?), ?, ?) returning *',
            [$userId, Crypto::generateId(32), AuthConfig::SESSION_SECONDS, $ip ?? '', $userAgent ?? ''],
        );
    }

    private function setSessionCookie(AuthRequest $request, string $token): void
    {
        $request->setCookies[] = $this->config->signedCookie('session_token', $token, AuthConfig::SESSION_SECONDS);
    }

    private function deleteSessionCookies(AuthRequest $request): void
    {
        foreach (['session_token', 'session_data', 'dont_remember'] as $name) {
            $request->setCookies[] = $this->config->expiredCookie($name);
        }
    }

    /** The verified value of one of our signed cookies, or null. */
    private function cookie(AuthRequest $request, string $name): ?string
    {
        return Crypto::verifyCookieValue($request->cookies[$this->config->cookieName($name)] ?? null, $this->config->secret);
    }

    // --------------------------------------------- link and challenge tokens

    private function createVerification(Tx $tx, string $identifier, string $value, int $seconds): void
    {
        $tx->execute(
            'insert into auth.verifications (identifier, value, expires_at) values (?, ?, now() + make_interval(secs => ?))',
            [Crypto::hashIdentifier($identifier), $value, $seconds],
        );
    }

    /** @return array{id: string, value: string, expired: bool, expires_in: int}|null */
    private function findVerification(Tx $tx, string $identifier): ?array
    {
        return $tx->first(
            'select id, value, expires_at < now() as expired, greatest(0, ceil(extract(epoch from expires_at - now())))::int as expires_in
             from auth.verifications where identifier = ? order by created_at desc limit 1',
            [Crypto::hashIdentifier($identifier)],
        );
    }

    /** Deletes the token and returns it; null when it is missing, expired or already used. */
    private function consumeVerification(Tx $tx, string $identifier): ?array
    {
        $rows = $tx->select(
            'delete from auth.verifications where identifier = ? returning value, expires_at < now() as expired, created_at',
            [Crypto::hashIdentifier($identifier)],
        );
        if ($rows === []) {
            return null;
        }
        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $rows[0]['expired'] ? null : $rows[0];
    }

    // -------------------------------------------------------------- passwords

    private function validatePassword(string $userId, string $password): bool
    {
        $this->assertNotTooLong($password);
        $stored = $this->db->withAuthContext(fn (Tx $tx) => $tx->value("select password from auth.accounts where user_id = ? and provider_id = 'credential'", [$userId]));

        return is_string($stored) && $this->passwords->verify($stored, $password);
    }

    private function assertNotTooShort(string $password): void
    {
        if (mb_strlen($password) < AuthConfig::MIN_PASSWORD_LENGTH) {
            throw AuthException::code(400, 'PASSWORD_TOO_SHORT');
        }
    }

    private function assertNotTooLong(string $password): void
    {
        if (mb_strlen($password) > AuthConfig::MAX_PASSWORD_LENGTH) {
            throw AuthException::code(400, 'PASSWORD_TOO_LONG');
        }
    }

    // ------------------------------------------------------------ rate limit

    /**
     * Fixed window per client address and endpoint, stored in auth.rate_limits
     * so it holds across PHP workers. Callers without a verifiable address
     * share one bucket per endpoint. Returns seconds to wait, or null.
     */
    private function consumeRateLimit(?string $clientIp, string $path): ?int
    {
        [$window, $max] = self::RATE_LIMITS[$path] ?? (str_starts_with($path, '/two-factor/') ? [10, 3] : [60, 60]);
        $key = ($clientIp ?? 'no-trusted-ip').'|'.$path;
        $windowMs = $window * 1000;

        return $this->db->withAuthContext(function (Tx $tx) use ($key, $windowMs, $max) {
            $now = (int) floor(microtime(true) * 1000);
            // One statement decides: new key, expired window, or one more inside the window.
            $allowed = $tx->first(
                'insert into auth.rate_limits (key, count, last_request) values (?, 1, ?)
                 on conflict (key) do update set
                   count = case when ? - auth.rate_limits.last_request >= ? then 1 else auth.rate_limits.count + 1 end,
                   last_request = excluded.last_request
                 where ? - auth.rate_limits.last_request >= ? or auth.rate_limits.count < ?
                 returning count',
                [$key, $now, $now, $windowMs, $now, $windowMs, $max],
            );
            if ($allowed !== null) {
                if ($allowed['count'] === 1) {
                    // A window just started: drop rows older than the longest window.
                    $tx->execute('delete from auth.rate_limits where last_request < ?', [$now - 60000]);
                }

                return null;
            }
            $last = (int) $tx->value('select last_request from auth.rate_limits where key = ?', [$key]);

            return max(1, (int) ceil(($last + $windowMs - $now) / 1000));
        });
    }

    // ---------------------------------------------------------------- output

    private function userOutput(array $user): array
    {
        return [
            'id' => $user['id'],
            'createdAt' => self::iso($user['created_at']),
            'updatedAt' => self::iso($user['updated_at']),
            'email' => $user['email'],
            'emailVerified' => (bool) $user['email_verified'],
            'name' => $user['name'],
            'image' => $user['image'],
            'twoFactorEnabled' => (bool) $user['two_factor_enabled'],
            'disabledAt' => $user['disabled_at'] === null ? null : self::iso($user['disabled_at']),
        ];
    }

    private function sessionOutput(array $session): array
    {
        return [
            'id' => $session['id'],
            'expiresAt' => self::iso($session['expires_at']),
            'token' => $session['token'],
            'createdAt' => self::iso($session['created_at']),
            'updatedAt' => self::iso($session['updated_at']),
            'ipAddress' => $session['ip_address'],
            'userAgent' => $session['user_agent'],
            'userId' => $session['user_id'],
        ];
    }

    /** PostgreSQL timestamptz text to the JavaScript ISO form (milliseconds, Z). */
    private static function iso(string $timestamp): string
    {
        return (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}

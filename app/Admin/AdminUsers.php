<?php

namespace App\Admin;

use App\Auth\AuthConfig;
use App\Auth\AuthService;
use App\Auth\Crypto;
use App\Database\Db;
use App\Database\Tx;
use App\Mail\TemplateMailer;
use App\Support\Audit;
use RuntimeException;

/**
 * Staff account administration (the user-management part of
 * src/lib/admin.server.ts). Callers authorize first: every method here is
 * Super Admin only unless stated otherwise.
 */
final class AdminUsers
{
    private const ACCOUNT_EMAIL_EVENTS = ['admin_user.resend_invite', 'admin_user.password_reset'];

    private const PER_TARGET_PER_HOUR = 3;

    private const PER_ACTOR_PER_HOUR = 20;

    public function __construct(
        private readonly Db $db,
        private readonly AuthService $auth,
        private readonly Audit $audit,
        private readonly TemplateMailer $mailer,
        private readonly \Closure $env,
    ) {}

    /**
     * Reads accounts, roles and sign-in state (no credentials).
     *
     * @return list<array{id: string, email: ?string, role: string, status: string, activated: bool, created_at: string, last_login_at: ?string}>
     */
    public function list(): array
    {
        $rows = $this->db->withServiceContext(fn (Tx $tx) => $tx->select(
            "select u.id, u.email, u.disabled_at, u.created_at, p.last_login_at,
                    exists (select 1 from auth.accounts a
                            where a.user_id = u.id and a.provider_id = 'credential') as activated,
                    coalesce(array_to_json(array_agg(r.role::text) filter (where r.role is not null)), '[]')::text as roles
             from auth.users u
             left join public.profiles p on p.id = u.id
             left join public.user_roles r on r.user_id = u.id
             group by u.id, p.last_login_at
             order by u.created_at",
        ));

        return array_map(function (array $row) {
            $owned = json_decode($row['roles'], true);
            $role = 'none';
            foreach (['super_admin', 'admin', 'security_auditor'] as $candidate) {
                if (in_array($candidate, $owned, true)) {
                    $role = $candidate;
                    break;
                }
            }

            return [
                'id' => $row['id'],
                'email' => $row['email'],
                'role' => $role,
                'status' => $row['disabled_at'] !== null ? 'disabled' : 'active',
                'activated' => (bool) $row['activated'],
                'created_at' => $row['created_at'],
                'last_login_at' => $row['last_login_at'],
            ];
        }, $rows);
    }

    /**
     * Origin used in activation and reset links: the configured portal address,
     * never a request header.
     */
    public function accountLinkOrigin(): string
    {
        $env = $this->env;
        $value = $env('ADMIN_SITE_URL') ?: ($env('AUTH_BASE_URL') ?: $env('PUBLIC_SITE_URL'));
        if (! $value) {
            throw new RuntimeException('ADMIN_SITE_URL is not configured');
        }

        return AuthConfig::origin($value);
    }

    /**
     * Sends a fresh activation link (accounts without a password, including
     * accounts imported from the previous platform) or a password reset link
     * (activated accounts) to the registered address. Tokens never reach this
     * method's result, logs or the audit log. Roles are never changed.
     *
     * @return array{ok: true, email: string}
     */
    public function sendAccountEmail(string $actorId, ?string $actorEmail, string $targetUserId, string $kind): array
    {
        $eventType = $kind === 'invite' ? 'admin_user.resend_invite' : 'admin_user.password_reset';
        $target = null;
        foreach ($this->list() as $user) {
            if ($user['id'] === $targetUserId) {
                $target = $user;
            }
        }
        $fail = function (string $reason, string $message) use ($eventType, $actorId, $actorEmail, $targetUserId, &$target): never {
            $this->audit->record($eventType, $actorId, $actorEmail, ['target_user' => $targetUserId, 'email' => $target['email'] ?? null, 'result' => 'failed', 'reason' => $reason]);
            throw new RuntimeException($message);
        };

        if ($target === null || ! $target['email']) {
            $fail('not_found', 'Account not found');
        }
        if ($target['status'] === 'disabled') {
            $fail('disabled', 'This account is disabled. Re-enable it before sending emails.');
        }
        if ($target['role'] === 'none') {
            $fail('no_role', 'This account has no administrator role.');
        }
        if ($kind === 'invite' && $target['activated']) {
            $fail('already_activated', 'This account is already activated — send a password reset instead.');
        }
        if ($kind === 'reset' && ! $target['activated']) {
            $fail('not_activated', 'This account has not been activated — resend the invitation instead.');
        }

        $this->assertAccountEmailRateLimit($actorId, $targetUserId);

        if (! $this->deliverAccountLink($target['id'], $target['email'], $kind)) {
            $fail('delivery_error', 'The email could not be sent. Please try again later.');
        }

        $this->audit->record($eventType, $actorId, $actorEmail, ['target_user' => $targetUserId, 'email' => $target['email'], 'result' => 'sent']);

        return ['ok' => true, 'email' => $target['email']];
    }

    /**
     * Creates a staff account with a new UUID and the given role, then either
     * emails an activation link or sets a temporary password. Never stores or
     * returns a link token.
     *
     * @param  array{email: string, sendInvite: bool, temporaryPassword?: ?string, role?: string}  $input
     * @return array{id: string}
     */
    public function create(string $actorId, ?string $actorEmail, array $input): array
    {
        $role = $input['role'] ?? 'admin';
        if (! in_array($role, ['admin', 'security_auditor'], true)) {
            throw new RuntimeException('Unable to create the administrator');
        }
        $email = strtolower(trim($input['email']));
        $temporary = $input['temporaryPassword'] ?? null;
        if (! $input['sendInvite'] && (! $temporary || mb_strlen($temporary) < 12)) {
            throw new RuntimeException('A temporary password of at least 12 characters is required');
        }

        $userId = Crypto::uuid();
        try {
            $this->db->withServiceContext(function (Tx $tx) use ($email, $userId, $role) {
                if ($tx->first('select 1 from auth.users where email = ?', [$email]) !== null) {
                    throw new RuntimeException('An account with this email already exists');
                }
                $tx->execute('insert into auth.users (id, email) values (?, ?)', [$userId, $email]);
                $tx->execute('insert into public.profiles (id, email) values (?, ?)', [$userId, $email]);
                $tx->execute('insert into public.user_roles (user_id, role) values (?, ?)', [$userId, $role]);
            });
        } catch (\Throwable $error) {
            throw new RuntimeException($error->getMessage() === 'An account with this email already exists' ? $error->getMessage() : 'Unable to create the administrator');
        }

        $undo = function () use ($userId) {
            try {
                $this->db->withServiceContext(fn (Tx $tx) => $tx->execute('delete from auth.users where id = ?', [$userId]));
            } catch (\Throwable) {
            }
        };
        if ($input['sendInvite']) {
            if (! $this->deliverAccountLink($userId, $email, 'invite')) {
                $undo();
                throw new RuntimeException('Unable to invite the administrator');
            }
        } else {
            try {
                $this->auth->setInitialPassword($userId, $temporary);
            } catch (\Throwable) {
                $undo();
                throw new RuntimeException('Unable to create the administrator');
            }
        }

        $this->audit->record('admin_user.create', $actorId, $actorEmail, ['target_user' => $userId, 'email' => $email, 'role' => $role, 'invited' => (bool) $input['sendInvite']]);

        return ['id' => $userId];
    }

    /**
     * Disabling sets auth.users.disabled_at and ends every session of the
     * account at once; passwords and MFA are never touched.
     *
     * @return array{ok: true}
     */
    public function setEnabled(string $actorId, ?string $actorEmail, string $targetUserId, bool $enabled): array
    {
        if (! $enabled) {
            if ($targetUserId === $actorId) {
                throw new RuntimeException('You cannot disable your own account');
            }
            $users = $this->list();
            foreach ($users as $user) {
                if ($user['id'] === $targetUserId && $user['role'] === 'super_admin') {
                    $activeSupers = array_filter($users, fn ($u) => $u['role'] === 'super_admin' && $u['status'] === 'active');
                    if (count($activeSupers) <= 1) {
                        throw new RuntimeException('The last active Super Admin cannot be disabled');
                    }
                }
            }
        }

        $status = $enabled ? 'active' : 'disabled';
        try {
            $updated = $this->db->withServiceContext(function (Tx $tx) use ($enabled, $targetUserId, $status) {
                $rows = $tx->execute('update auth.users set disabled_at = '.($enabled ? 'null' : 'now()').', updated_at = now() where id = ?', [$targetUserId]);
                if ($rows !== 1) {
                    return false;
                }
                if (! $enabled) {
                    $tx->execute('delete from auth.sessions where user_id = ?', [$targetUserId]);
                }
                $tx->execute('insert into public.profiles (id, status) values (?, ?) on conflict (id) do update set status = excluded.status', [$targetUserId, $status]);

                return true;
            });
        } catch (\Throwable) {
            $updated = false;
        }
        if (! $updated) {
            throw new RuntimeException('Unable to update the administrator account');
        }

        $this->audit->record($enabled ? 'admin_user.enable' : 'admin_user.disable', $actorId, $actorEmail, ['target_user' => $targetUserId]);

        return ['ok' => true];
    }

    /**
     * Records a successful administrator sign-in.
     *
     * @return array{ok: true}
     */
    public function recordLogin(string $userId, ?string $email): array
    {
        $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
            'insert into public.profiles (id, email, last_login_at) values (?, ?, now())
             on conflict (id) do update set email = excluded.email, last_login_at = excluded.last_login_at',
            [$userId, $email],
        ));
        $this->audit->record('auth.login', $userId, $email);

        return ['ok' => true];
    }

    /** Durable rate limit based on the audit log (works across server instances). */
    private function assertAccountEmailRateLimit(string $actorId, string $targetId): void
    {
        $counts = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
            "select count(*) filter (where details->>'target_user' = ?)::int as target_count,
                    count(*) filter (where actor_id = ?)::int as actor_count
             from public.audit_log
             where event_type in (?, ?) and created_at >= now() - interval '1 hour'",
            [$targetId, $actorId, ...self::ACCOUNT_EMAIL_EVENTS],
        ));
        if (($counts['target_count'] ?? 0) >= self::PER_TARGET_PER_HOUR) {
            throw new RuntimeException('Too many emails were sent to this account in the last hour. Please try again later.');
        }
        if (($counts['actor_count'] ?? 0) >= self::PER_ACTOR_PER_HOUR) {
            throw new RuntimeException('You have sent too many account emails in the last hour. Try again later.');
        }
    }

    /** Issues a single-use link and emails it. Returns false when delivery failed. */
    private function deliverAccountLink(string $userId, string $email, string $kind): bool
    {
        try {
            $link = $this->auth->issueAccountLink($userId, $kind, $this->accountLinkOrigin());

            return $this->mailer->send('admin-account-link', $email, ['kind' => $kind, 'url' => $link['url'], 'validFor' => $link['validFor']]);
        } catch (\Throwable $error) {
            // Never log the link itself.
            error_log('[account-email] delivery failed '.json_encode(['kind' => $kind, 'error' => $error::class]));

            return false;
        }
    }
}

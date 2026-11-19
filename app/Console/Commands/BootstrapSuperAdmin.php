<?php

namespace App\Console\Commands;

use App\Auth\AuthConfig;
use App\Auth\AuthService;
use App\Auth\Crypto;
use App\Database\Db;
use App\Database\Tx;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * One-off activation link for the first Super Admin of an environment, when
 * no Super Admin can sign in yet (port of scripts/auth/bootstrap-super-admin.ts).
 * Refuses to run once any Super Admin has a password.
 *
 * The link is printed once. Hand it to the person over a trusted channel; it
 * works once and expires after 72 hours. The event is written to the audit log.
 */
class BootstrapSuperAdmin extends Command
{
    protected $signature = 'onehealth:bootstrap-super-admin {email}
        {--create : On an empty database, create the Super Admin account first}';

    protected $description = 'Print a single-use activation link for the first Super Admin of this environment';

    public function handle(Db $db, AuthService $auth, Audit $audit): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $site = config('onehealth.env.ADMIN_SITE_URL') ?: config('onehealth.env.AUTH_BASE_URL');
        if ($email === '' || ! $site) {
            $this->error('An email address is required, and ADMIN_SITE_URL must be set.');

            return 2;
        }

        $state = $db->withServiceContext(function (Tx $tx) use ($email) {
            $active = $tx->first(
                "select 1 from public.user_roles r join auth.accounts a on a.user_id = r.user_id
                 join auth.users u on u.id = r.user_id
                 where r.role = 'super_admin' and a.provider_id = 'credential' and u.disabled_at is null limit 1");
            if ($active !== null) {
                return ['error' => 'A Super Admin can already sign in. Use User Management → Resend invitation instead.'];
            }
            $user = $tx->first(
                "select u.id, u.disabled_at,
                        exists (select 1 from public.user_roles r where r.user_id = u.id and r.role = 'super_admin') as is_super
                 from auth.users u where u.email = ?", [$email]);
            if ($user === null && $this->option('create')) {
                if ($tx->first("select 1 from public.user_roles where role = 'super_admin' limit 1") !== null) {
                    return ['error' => 'A Super Admin account already exists. Run the command with its email address.'];
                }
                $id = Crypto::uuid();
                $tx->execute('insert into auth.users (id, email) values (?, ?)', [$id, $email]);
                $tx->execute('insert into public.profiles (id, email) values (?, ?)', [$id, $email]);
                $tx->execute("insert into public.user_roles (user_id, role) values (?, 'super_admin')", [$id]);

                return ['id' => $id, 'created' => true];
            }
            if ($user === null || ! $user['is_super']) {
                return ['error' => 'No Super Admin account with that email.'];
            }
            if ($user['disabled_at'] !== null) {
                return ['error' => 'That account is disabled.'];
            }

            return ['id' => $user['id'], 'created' => false];
        });
        if (isset($state['error'])) {
            $this->error($state['error']);

            return self::FAILURE;
        }

        $link = $auth->issueAccountLink($state['id'], 'invite', AuthConfig::origin($site));
        $audit->record('admin_user.bootstrap_link', null, null, ['target_user' => $state['id'], 'email' => $email] + ($state['created'] ? ['created' => true] : []));
        $this->line("Activation link for {$email} (valid {$link['validFor']}, single use):");
        $this->line($link['url']);

        return self::SUCCESS;
    }
}

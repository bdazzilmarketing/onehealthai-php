<?php

namespace App\Auth;

use App\Database\Tx;
use App\Database\UserScope;

/**
 * Role gates for the Admin Portal (port of the auth/roles part of
 * src/lib/admin.server.ts). The checks run in the caller's own database
 * context and are decided by the SQL functions of the original schema.
 */
final class Roles
{
    public static function isSuperAdmin(UserScope $db, string $userId): bool
    {
        return self::check($db, 'select public.is_super_admin(?) as v', $userId);
    }

    public static function isUserAdmin(UserScope $db, string $userId): bool
    {
        return self::check($db, "select public.has_role(?, 'admin'::public.app_role) as v", $userId)
            || self::isSuperAdmin($db, $userId);
    }

    public static function isSecurityAuditor(UserScope $db, string $userId): bool
    {
        return self::check($db, 'select public.is_security_auditor(?) as v', $userId);
    }

    public static function requireAdmin(UserScope $db, string $userId): void
    {
        if (! self::isUserAdmin($db, $userId)) {
            throw new \RuntimeException('Forbidden: administrator role required');
        }
    }

    /** Read-only access: admin, super admin or security auditor. Never use for mutations. */
    public static function requireAdminViewer(UserScope $db, string $userId): void
    {
        if (self::isUserAdmin($db, $userId)) {
            return;
        }
        if (! self::isSecurityAuditor($db, $userId)) {
            throw new \RuntimeException('Forbidden: administrator role required');
        }
    }

    /** Read-only user listing: super admin or security auditor. */
    public static function requireUserListViewer(UserScope $db, string $userId): void
    {
        if (self::isSuperAdmin($db, $userId)) {
            return;
        }
        if (! self::isSecurityAuditor($db, $userId)) {
            throw new \RuntimeException('Forbidden: super administrator role required');
        }
    }

    public static function requireSuperAdmin(UserScope $db, string $userId): void
    {
        if (! self::isSuperAdmin($db, $userId)) {
            throw new \RuntimeException('Forbidden: super administrator role required');
        }
    }

    private static function check(UserScope $db, string $sql, string $userId): bool
    {
        try {
            return (bool) $db->run(fn (Tx $tx) => $tx->value($sql, [$userId]));
        } catch (\Throwable) {
            throw new \RuntimeException('Unable to verify administrator role');
        }
    }
}

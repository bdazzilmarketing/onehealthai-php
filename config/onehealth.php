<?php

/*
 * Application settings read from the environment. Code reads these through
 * config('onehealth.env.NAME') so they keep working when Laravel's
 * configuration is cached (env() returns null outside config files then).
 */
$names = [
    // Staff authentication
    'AUTH_SECRET', 'AUTH_BASE_URL', 'ADMIN_SITE_URL', 'PUBLIC_SITE_URL',
    'AUTH_MFA_REQUIRED_ROLES', 'AUTH_PASSWORD_ALGORITHM',
    // Client address resolution behind a proxy
    'TRUSTED_PROXY_MODE', 'TRUSTED_PROXY_CIDRS', 'CLIENT_IP_HEADER', 'VERCEL',
    // Database schema installation (console only)
    'MIGRATION_DATABASE_URL',
];

$env = [];
foreach ($names as $name) {
    $env[$name] = env($name);
}

return ['env' => $env];

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
    // Private file storage
    'FILE_STORE_DRIVER', 'FILE_STORE_PATH', 'FILE_STORE_S3_ENDPOINT', 'FILE_STORE_S3_BUCKET',
    'FILE_STORE_S3_REGION', 'FILE_STORE_S3_ACCESS_KEY_ID', 'FILE_STORE_S3_SECRET_ACCESS_KEY',
    // Integrations shown on the admin dashboard
    'OPENAI_API_KEY', 'EMAIL_DRIVER', 'SMTP_HOST', 'GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID',
    'GRAPH_CLIENT_SECRET', 'GRAPH_SENDER', 'CRON_SECRET', 'MAKE_WEBHOOK_URL', 'MAKE_WEBHOOK_SECRET',
    // AI assistant: usage-limit hashing and optional content-free QA diagnostics
    'AI_QUOTA_HASH_SECRET', 'AI_QA_DIAGNOSTICS_TOKEN', 'AI_QA_DIAGNOSTICS_EXPIRES_AT',
    // Database schema installation (console only)
    'MIGRATION_DATABASE_URL',
];

$env = [];
foreach ($names as $name) {
    $env[$name] = env($name);
}

return ['env' => $env];

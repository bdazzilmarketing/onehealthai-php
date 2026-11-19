<?php

namespace App\Mail;

use RuntimeException;

/**
 * Outbound email transport from the environment (port of
 * src/lib/email/transport.server.ts).
 *
 * EMAIL_DRIVER
 *   smtp   SMTP_HOST, SMTP_PORT (default 587), SMTP_USER, SMTP_PASSWORD,
 *          SMTP_SECURE=true for implicit TLS (port 465). STARTTLS is required
 *          otherwise; TLS 1.2 is the minimum.
 *   graph  Microsoft Graph sendMail: GRAPH_TENANT_ID, GRAPH_CLIENT_ID,
 *          GRAPH_CLIENT_SECRET, GRAPH_SENDER (the mailbox the app may send as).
 *   log    development only: writes the subject and recipient to the log,
 *          never the body. Refused in production.
 * Unset means email is not configured: sends fail closed.
 *
 * EMAIL_ALLOWED_RECIPIENTS (staging, test): comma-separated addresses and/or
 *
 * @domain entries. When set, any other recipient is refused before the
 * message reaches the driver, whatever code path sends it.
 */
final class Transports
{
    /**
     * Parses EMAIL_ALLOWED_RECIPIENTS; null when unset (no restriction).
     *
     * @return list<string>|null
     */
    public static function parseRecipientAllowlist(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $entries = array_values(array_filter(array_map(fn (string $entry) => mb_strtolower(trim($entry), 'UTF-8'), explode(',', $value)), fn (string $entry) => $entry !== ''));
        foreach ($entries as $entry) {
            if (preg_match('/^[^@\s]*@[^@\s]+\z/u', $entry) !== 1) {
                throw new RuntimeException('EMAIL_ALLOWED_RECIPIENTS must list addresses or @domain entries');
            }
        }

        return $entries;
    }

    /** @param  \Closure(string): mixed  $env */
    public static function fromEnv(\Closure $env, bool $production): EmailTransport
    {
        $driver = self::driver($env, $production);
        $allowed = self::parseRecipientAllowlist($env('EMAIL_ALLOWED_RECIPIENTS'));

        return $allowed !== null ? new RestrictedTransport($driver, $allowed) : $driver;
    }

    private static function driver(\Closure $env, bool $production): EmailTransport
    {
        $need = function (string $name) use ($env): string {
            $value = $env($name);
            if (! $value) {
                throw new RuntimeException("{$name} is not configured");
            }

            return (string) $value;
        };
        switch ($env('EMAIL_DRIVER')) {
            case 'smtp':
                return new SmtpTransport($need('SMTP_HOST'), (int) ($env('SMTP_PORT') ?: 587), $env('SMTP_SECURE') === 'true' || $env('SMTP_SECURE') === true, $env('SMTP_USER'), $env('SMTP_PASSWORD'));
            case 'graph':
                return new GraphTransport($need('GRAPH_TENANT_ID'), $need('GRAPH_CLIENT_ID'), $need('GRAPH_CLIENT_SECRET'), $need('GRAPH_SENDER'));
            case 'log':
                if ($production) {
                    throw new RuntimeException('EMAIL_DRIVER=log is not allowed in production');
                }

                return new LogTransport;
            default:
                throw new RuntimeException('Email is not configured (EMAIL_DRIVER)');
        }
    }
}

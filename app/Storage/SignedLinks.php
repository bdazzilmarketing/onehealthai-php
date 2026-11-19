<?php

namespace App\Storage;

/**
 * Short-lived signed links to private files (port of
 * src/lib/storage/signed-url.server.ts). The link alone is not enough: the
 * download route also requires a signed-in admin viewer.
 */
final class SignedLinks
{
    public function __construct(private readonly ?string $secret) {}

    /** Path + query for a report download that expires after $seconds. */
    public function signedReportPath(string $submissionId, bool $download, int $seconds = 60, ?int $now = null): string
    {
        $expires = ($now ?? time()) + $seconds;
        $query = http_build_query([
            'exp' => (string) $expires,
            'dl' => $download ? '1' : '0',
            'sig' => $this->sign("report:{$submissionId}", $expires, $download),
        ]);

        return "/api/files/report/{$submissionId}?{$query}";
    }

    /**
     * Verifies a report link.
     *
     * @param  array<string, mixed>  $params  the query parameters
     * @return array{download: bool}|null
     */
    public function verifyReportLink(string $submissionId, array $params, ?int $now = null): ?array
    {
        $exp = $params['exp'] ?? null;
        $download = ($params['dl'] ?? null) === '1';
        $given = is_string($params['sig'] ?? null) ? $params['sig'] : '';
        if (! is_string($exp) || preg_match('/^\d{1,15}$/', $exp) !== 1 || (int) $exp < ($now ?? time())) {
            return null;
        }
        if (! hash_equals($this->sign("report:{$submissionId}", (int) $exp, $download), $given)) {
            return null;
        }

        return ['download' => $download];
    }

    private function sign(string $resource, int $expires, bool $download): string
    {
        if ($this->secret === null || strlen($this->secret) < 32) {
            throw new \RuntimeException('AUTH_SECRET must be at least 32 characters');
        }
        // Separate key for this purpose, derived from the deployment secret.
        $key = hash_hmac('sha256', 'onehealth:file-links:v1', $this->secret, true);
        $mac = hash_hmac('sha256', "{$resource}\n{$expires}\n".($download ? 1 : 0), $key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}

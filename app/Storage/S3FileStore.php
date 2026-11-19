<?php

namespace App\Storage;

use Illuminate\Support\Facades\Http;

/**
 * Any S3-compatible service, addressed path-style
 * (endpoint/bucket/<store bucket>/<path>) with AWS Signature Version 4.
 */
final class S3FileStore implements FileStore
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
    ) {}

    public function put(string $bucket, string $path, string $bytes, string $contentType, bool $overwrite = false): void
    {
        $headers = ['content-type' => $contentType];
        // Conditional write: refuse to replace an existing object unless asked to.
        if (! $overwrite) {
            $headers['if-none-match'] = '*';
        }
        $url = $this->url($bucket, $path);
        $response = Http::withHeaders($this->sign('PUT', $url, $headers, $bytes))->timeout(60)->withBody($bytes, $contentType)->put($url);
        if ($response->status() === 412) {
            throw new \RuntimeException('File already exists');
        }
        if (! $response->successful()) {
            throw new \RuntimeException("File storage write failed ({$response->status()})");
        }
    }

    public function get(string $bucket, string $path): ?array
    {
        $url = $this->url($bucket, $path);
        $response = Http::withHeaders($this->sign('GET', $url, [], ''))->timeout(60)->get($url);
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new \RuntimeException("File storage read failed ({$response->status()})");
        }

        return ['bytes' => $response->body(), 'contentType' => $response->header('content-type') ?: 'application/octet-stream'];
    }

    private function url(string $bucket, string $path): string
    {
        FileKeys::assertBucket($bucket);
        FileKeys::assertSafeKey($path);
        $key = implode('/', array_map(rawurlencode(...), [$bucket, ...explode('/', $path)]));

        return rtrim($this->endpoint, '/').'/'.rawurlencode($this->bucket).'/'.$key;
    }

    /**
     * @param  array<string, string>  $headers  lower-case names
     * @return array<string, string> the headers to send, with the signature
     */
    private function sign(string $method, string $url, array $headers, string $body, ?int $now = null): array
    {
        $parts = parse_url($url);
        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $time = gmdate('Ymd\THis\Z', $now ?? time());
        $date = substr($time, 0, 8);
        $headers += ['host' => $host, 'x-amz-content-sha256' => hash('sha256', $body), 'x-amz-date' => $time];
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim(preg_replace('/\s+/', ' ', $value))."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $request = implode("\n", [$method, $parts['path'] ?? '/', '', $canonicalHeaders, $signedHeaders, $headers['x-amz-content-sha256']]);
        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $time, $scope, hash('sha256', $request)]);
        $key = hash_hmac('sha256', $date, 'AWS4'.$this->secretAccessKey, true);
        foreach ([$this->region, 's3', 'aws4_request'] as $step) {
            $key = hash_hmac('sha256', $step, $key, true);
        }
        $signature = hash_hmac('sha256', $toSign, $key);
        unset($headers['host'], $headers['content-type']);

        return $headers + ['authorization' => "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}"];
    }
}

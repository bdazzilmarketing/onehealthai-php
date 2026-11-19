<?php

namespace App\Storage;

/** Files under one directory (a persistent volume), one sub-directory per bucket. */
final class LocalFileStore implements FileStore
{
    private readonly string $base;

    public function __construct(string $root)
    {
        $root = str_starts_with($root, '/') ? $root : getcwd().'/'.$root;
        $this->base = rtrim($root, '/');
    }

    public function put(string $bucket, string $path, string $bytes, string $contentType, bool $overwrite = false): void
    {
        $full = $this->target($bucket, $path);
        $directory = dirname($full);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('File storage write failed');
        }
        // Write a temporary file first so readers never see a partial file. A
        // hard link publishes it only if the name is free; rename replaces.
        $temp = $full.'.'.getmypid().'.'.bin2hex(random_bytes(6)).'.tmp';
        $handle = @fopen($temp, 'x');
        if ($handle === false) {
            throw new \RuntimeException('File storage write failed');
        }
        try {
            chmod($temp, 0600);
            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('File storage write failed');
            }
            fclose($handle);
            $handle = null;
            if ($overwrite) {
                if (! @rename($temp, $full)) {
                    throw new \RuntimeException('File storage write failed');
                }
            } elseif (! @link($temp, $full)) {
                throw new \RuntimeException(file_exists($full) ? 'File already exists' : 'File storage write failed');
            }
        } finally {
            if ($handle) {
                fclose($handle);
            }
            @unlink($temp);
        }
        file_put_contents($full.'.meta', json_encode(['contentType' => $contentType], JSON_UNESCAPED_SLASHES));
        chmod($full.'.meta', 0600);
    }

    public function get(string $bucket, string $path): ?array
    {
        $full = $this->target($bucket, $path);
        if (! is_file($full)) {
            return null;
        }
        $bytes = file_get_contents($full);
        if ($bytes === false) {
            throw new \RuntimeException('File storage read failed');
        }
        $meta = is_file($full.'.meta') ? json_decode((string) file_get_contents($full.'.meta'), true) : null;

        return ['bytes' => $bytes, 'contentType' => is_array($meta) && is_string($meta['contentType'] ?? null) ? $meta['contentType'] : 'application/octet-stream'];
    }

    private function target(string $bucket, string $path): string
    {
        FileKeys::assertBucket($bucket);
        FileKeys::assertSafeKey($path);

        return $this->base.'/'.$bucket.'/'.$path;
    }
}

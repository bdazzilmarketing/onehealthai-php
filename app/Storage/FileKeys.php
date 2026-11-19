<?php

namespace App\Storage;

final class FileKeys
{
    /**
     * Object keys come from server code (reference ids, version numbers,
     * uploaded filenames), but are still checked so a crafted filename cannot
     * leave the bucket: relative segments, absolute paths and control
     * characters are refused.
     */
    public static function assertSafeKey(string $path): void
    {
        $bad = $path === ''
            || mb_strlen($path, 'UTF-8') > 512
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1;
        foreach ($bad ? [] : explode('/', $path) as $segment) {
            $bad = $bad || $segment === '' || $segment === '.' || $segment === '..';
        }
        if ($bad) {
            throw new \RuntimeException('Invalid file path');
        }
    }

    public static function assertBucket(string $bucket): void
    {
        if (! in_array($bucket, FileStore::BUCKETS, true)) {
            throw new \RuntimeException('Invalid bucket');
        }
    }
}

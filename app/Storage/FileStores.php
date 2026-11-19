<?php

namespace App\Storage;

final class FileStores
{
    /**
     * FILE_STORE_DRIVER
     *   local  files under FILE_STORE_PATH
     *   s3     FILE_STORE_S3_ENDPOINT, _BUCKET, _REGION, _ACCESS_KEY_ID, _SECRET_ACCESS_KEY
     * Unset means storage is not configured: writes and reads fail closed.
     *
     * @param  array<string, ?string>  $env
     */
    public static function fromEnv(array $env): FileStore
    {
        $driver = $env['FILE_STORE_DRIVER'] ?? null;
        $need = function (string $name) use ($env): string {
            $value = $env[$name] ?? null;
            if (! is_string($value) || $value === '') {
                throw new \RuntimeException("{$name} is not configured");
            }

            return $value;
        };
        if ($driver === 'local') {
            return new LocalFileStore($need('FILE_STORE_PATH'));
        }
        if ($driver === 's3') {
            return new S3FileStore(
                $need('FILE_STORE_S3_ENDPOINT'),
                $need('FILE_STORE_S3_BUCKET'),
                ($env['FILE_STORE_S3_REGION'] ?? '') ?: 'auto',
                $need('FILE_STORE_S3_ACCESS_KEY_ID'),
                $need('FILE_STORE_S3_SECRET_ACCESS_KEY'),
            );
        }
        throw new \RuntimeException('File storage is not configured (FILE_STORE_DRIVER)');
    }
}

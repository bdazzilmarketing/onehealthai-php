<?php

namespace App\Storage;

/**
 * Private file storage (port of src/lib/storage/file-store.server.ts). Two
 * buckets are used: uploaded dataset workbooks and internal sales-report
 * PDFs. Nothing is ever public: files are read back only through authorised
 * server code.
 */
interface FileStore
{
    public const BUCKETS = ['dataset-imports', 'submission-reports'];

    /** @throws \RuntimeException "File already exists" unless $overwrite */
    public function put(string $bucket, string $path, string $bytes, string $contentType, bool $overwrite = false): void;

    /** @return array{bytes: string, contentType: string}|null */
    public function get(string $bucket, string $path): ?array;
}

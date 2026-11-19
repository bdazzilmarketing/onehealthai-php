<?php

namespace Tests\Unit;

use App\Storage\FileStores;
use App\Storage\LocalFileStore;
use App\Storage\S3FileStore;
use App\Storage\SignedLinks;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Private file storage and signed report links (ports the storage parts of src/lib/supporting-services.server.test.ts). */
final class StorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/onehealth-store-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_local_store_writes_privately_reads_back_and_refuses_silent_overwrites(): void
    {
        $store = new LocalFileStore($this->root);
        $store->put('dataset-imports', 'poct/v1-a.xlsx', "\x01\x02", 'application/x-test');
        $this->assertSame(['bytes' => "\x01\x02", 'contentType' => 'application/x-test'], $store->get('dataset-imports', 'poct/v1-a.xlsx'));
        $this->assertSame(0600, fileperms($this->root.'/dataset-imports/poct/v1-a.xlsx') & 0777);

        try {
            $store->put('dataset-imports', 'poct/v1-a.xlsx', "\x09", 'application/x-test');
            $this->fail('Expected the overwrite to be refused');
        } catch (\RuntimeException $error) {
            $this->assertSame('File already exists', $error->getMessage());
        }
        $store->put('dataset-imports', 'poct/v1-a.xlsx', "\x03", 'text/plain', overwrite: true);
        $this->assertSame(['bytes' => "\x03", 'contentType' => 'text/plain'], $store->get('dataset-imports', 'poct/v1-a.xlsx'));
        // No temporary files are left behind.
        $this->assertSame(['v1-a.xlsx', 'v1-a.xlsx.meta'], array_values(array_diff(scandir($this->root.'/dataset-imports/poct'), ['.', '..'])));
        $this->assertNull($store->get('dataset-imports', 'poct/missing.xlsx'));
    }

    public function test_refuses_paths_that_could_leave_the_bucket_and_unknown_buckets(): void
    {
        $store = new LocalFileStore($this->root);
        foreach (['../x', 'a/../../x', '/etc/passwd', 'a//b', 'a\\b', 'a/./b', '', "a\0b"] as $path) {
            try {
                $store->put('dataset-imports', $path, 'x', 'x/y');
                $this->fail("Expected {$path} to be refused");
            } catch (\RuntimeException $error) {
                $this->assertSame('Invalid file path', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('Invalid bucket');
        $store->put('public', 'a', 'x', 'x/y');
    }

    public function test_fails_closed_when_storage_is_not_configured(): void
    {
        foreach ([
            [[], 'File storage is not configured'],
            [['FILE_STORE_DRIVER' => 'local'], 'FILE_STORE_PATH'],
            [['FILE_STORE_DRIVER' => 's3', 'FILE_STORE_S3_ENDPOINT' => 'https://s3.test'], 'FILE_STORE_S3_BUCKET'],
        ] as [$env, $message]) {
            try {
                FileStores::fromEnv($env);
                $this->fail('Expected a configuration error');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
        $this->assertInstanceOf(LocalFileStore::class, FileStores::fromEnv(['FILE_STORE_DRIVER' => 'local', 'FILE_STORE_PATH' => $this->root]));
    }

    public function test_s3_store_signs_requests_uses_conditional_writes_and_maps_404_and_412(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push('', 200)
            ->push('', 412)
            ->push("\x07\x08", 200, ['content-type' => 'application/pdf'])
            ->push('', 404)
            ->push('', 500)
            ->push('', 200);
        $store = new S3FileStore('https://s3.example.test/', 'onehealth', 'auto', 'AKIDEXAMPLE', 'secret');

        $store->put('submission-reports', 'OH-1/OH-1 report.pdf', "\x01", 'application/pdf');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://s3.example.test/onehealth/submission-reports/OH-1/OH-1%20report.pdf'
            && preg_match('#^AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/\d{8}/auto/s3/aws4_request, SignedHeaders=content-type;host;if-none-match;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $request->header('authorization')[0]) === 1
            && $request->header('if-none-match') === ['*']
            && $request->header('content-type') === ['application/pdf']
            && $request->header('x-amz-content-sha256') === [hash('sha256', "\x01")]
            && $request->body() === "\x01");

        try {
            $store->put('submission-reports', 'OH-1/x.pdf', "\x01", 'application/pdf');
            $this->fail('Expected the existing object to be kept');
        } catch (\RuntimeException $error) {
            $this->assertSame('File already exists', $error->getMessage());
        }
        $this->assertSame(['bytes' => "\x07\x08", 'contentType' => 'application/pdf'], $store->get('submission-reports', 'OH-1/x.pdf'));
        $this->assertNull($store->get('submission-reports', 'OH-1/missing.pdf'));
        try {
            $store->get('submission-reports', 'OH-1/y.pdf');
            $this->fail('Expected a read failure');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('read failed (500)', $error->getMessage());
        }

        // No conditional header when overwriting.
        $store->put('submission-reports', 'a.pdf', "\x01", 'application/pdf', overwrite: true);
        $this->assertSame([], Http::recorded()->last()[0]->header('if-none-match'));

        $this->expectExceptionMessage('Invalid file path');
        $store->get('submission-reports', '../escape');
    }

    /** The worked example from the AWS Signature Version 4 documentation (GET Object with a Range header). */
    public function test_s3_signature_matches_the_published_reference_example(): void
    {
        $store = new S3FileStore('https://examplebucket.s3.amazonaws.com', 'b', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        $sign = new \ReflectionMethod($store, 'sign');
        $headers = $sign->invoke($store, 'GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['range' => 'bytes=0-9'], '', gmmktime(0, 0, 0, 5, 24, 2013));

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['authorization'],
        );
    }

    public function test_signed_report_links_accept_only_an_unexpired_untampered_link(): void
    {
        $links = new SignedLinks(str_repeat('s', 40));
        $id = '11111111-2222-3333-4444-555555555555';
        $now = 1_800_000_000;
        $path = $links->signedReportPath($id, true, 60, $now);
        $this->assertStringStartsWith("/api/files/report/{$id}?exp=".($now + 60).'&dl=1&sig=', $path);
        parse_str(parse_url($path, PHP_URL_QUERY), $query);

        $this->assertSame(['download' => true], $links->verifyReportLink($id, $query, $now + 59));
        $this->assertNull($links->verifyReportLink($id, $query, $now + 61));
        $this->assertNull($links->verifyReportLink('11111111-2222-3333-4444-555555555556', $query, $now));
        $this->assertNull($links->verifyReportLink($id, ['dl' => '0'] + $query, $now));
        $this->assertNull($links->verifyReportLink($id, ['exp' => (string) ($now + 600)] + $query, $now));
        $this->assertNull($links->verifyReportLink($id, ['sig' => 'x'] + $query, $now));
        $this->assertNull($links->verifyReportLink($id, [], $now));
        $this->assertNull((new SignedLinks(str_repeat('t', 40)))->verifyReportLink($id, $query, $now));

        $this->expectExceptionMessage('AUTH_SECRET must be at least 32 characters');
        (new SignedLinks('short'))->signedReportPath($id, false);
    }
}

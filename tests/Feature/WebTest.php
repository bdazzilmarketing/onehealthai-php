<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The web pages and the browser-facing guards: the page shell, the security
 * headers on every answer (port of src/lib/security-headers.test.ts) and the
 * same-origin rule for the /api/fn/ endpoints.
 */
final class WebTest extends TestCase
{
    public function test_every_page_address_answers_the_shell(): void
    {
        $shell = file_get_contents(resource_path('web/shell.html'));
        foreach (['/', '/calculator', '/ai', '/admin-activate?token=t', '/admin-reset-password', '/adminpanel', '/adminpanel/users',
            '/adminpanel/submissions', '/adminpanel/submissions/5b0c5a0e-0000-4000-8000-000000000000'] as $page) {
            $response = $this->get($page)->assertStatus(200);
            $this->assertSame($shell, $response->getContent(), $page);
            $this->assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
            // Pages keep no server-side state.
            $this->assertSame([], $response->headers->getCookies(), $page);
        }
        foreach (['/nope', '/adminpanel/nope', '/calculator/x', '/.env', '/adminpanel/submissions/a/b'] as $unknown) {
            $this->assertSame($shell, $this->get($unknown)->assertStatus(404)->getContent(), $unknown);
        }
        // Nothing under /api/ ever answers a page.
        $this->get('/api/nope')->assertStatus(404)->assertHeader('Content-Type', 'application/json');
        $this->get('/api')->assertStatus(404);
        $this->get('/up')->assertStatus(404);
    }

    public function test_the_shell_names_only_files_of_the_published_build(): void
    {
        $shell = file_get_contents(resource_path('web/shell.html'));
        $this->assertStringContainsString('<title>One Health AI Lab Calculator', $shell);
        preg_match_all('#"(/assets/[^"]+)"#', $shell, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $asset) {
            $this->assertFileExists(public_path(ltrim($asset, '/')), $asset);
        }
        $this->assertFileExists(public_path('favicon.png'));
        // Scripts and connections stay on this site; only the Google Fonts stylesheet is external.
        preg_match_all('#(?:src|href)="(https?://[^"]+)"#', $shell, $external);
        foreach ($external[1] as $url) {
            $this->assertMatchesRegularExpression('#^https://fonts\.(googleapis|gstatic)\.com#', $url);
        }
    }

    public function test_every_answer_carries_the_security_headers(): void
    {
        $this->assertSame(
            "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            ."font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; worker-src 'self' blob:; "
            ."object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            SecurityHeaders::CONTENT_SECURITY_POLICY,
        );
        foreach (['/', '/nope', '/api/health', '/api/nope', '/api/fn/getCalculatorCatalog', '/api/cron/maintenance'] as $uri) {
            $response = $this->get($uri);
            foreach (SecurityHeaders::HEADERS as $name => $value) {
                $this->assertSame($value, $response->headers->get($name), "{$uri} {$name}");
            }
        }
        $this->assertSame('DENY', $this->get('/')->headers->get('X-Frame-Options'));
        $this->assertSame('max-age=31536000; includeSubDomains', $this->get('/')->headers->get('Strict-Transport-Security'));
    }

    public function test_server_functions_refuse_cross_site_calls(): void
    {
        config(['onehealth.env.PUBLIC_SITE_URL' => 'https://onehealth.test/']);
        $own = Request::create(config('app.url'))->getSchemeAndHttpHost();
        $status = fn (array $headers) => $this->get('/api/fn/getAiAvailability', $headers)->getStatusCode();
        $refused = function (array $headers) {
            $this->get('/api/fn/getAiAvailability', $headers)->assertStatus(403)->assertContent('Forbidden');
        };

        // The browser's own statement wins over everything else.
        $this->assertNotSame(403, $status(['Sec-Fetch-Site' => 'same-origin']));
        foreach (['cross-site', 'same-site', 'none'] as $site) {
            $refused(['Sec-Fetch-Site' => $site, 'Origin' => $own]);
        }
        // Without it: the Origin, then the Referer, must be this site.
        $this->assertNotSame(403, $status(['Origin' => $own]));
        $this->assertNotSame(403, $status(['Origin' => 'https://onehealth.test']));
        $refused(['Origin' => 'https://evil.test', 'Referer' => "{$own}/calculator"]);
        $refused(['Origin' => 'null']);
        $this->assertNotSame(403, $status(['Referer' => 'https://onehealth.test/ai?x=1']));
        $this->assertNotSame(403, $status(['Referer' => $own]));
        $refused(['Referer' => 'https://onehealth.test.evil.test/']);
        $refused(['Referer' => 'https://evil.test/?https://onehealth.test/']);
        // A request that says nothing about where it comes from is refused.
        $refused([]);
        $this->post('/api/fn/calculateEstimate', [])->assertStatus(403);

        // The other endpoints have their own protection and are not affected.
        $this->get('/api/health')->assertStatus(200);
    }
}

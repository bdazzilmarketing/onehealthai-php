<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the web pages. The pages are the original React screens built as a
 * single-page app (frontend/); every page address answers the same shell and
 * the browser renders the page. Addresses that are not a page answer 404 with
 * the same shell, which shows "Page not found".
 */
final class WebController
{
    /** Page addresses, from frontend/src/routes. */
    private const PAGES = '#^/(|calculator|ai|admin-activate|admin-reset-password|adminpanel(/(ai|audit|data|history|integrations|notifications|published|settings|users|submissions(/[^/]+)?))?)$#';

    public static function shellPath(): string
    {
        return resource_path('web/shell.html');
    }

    public function __invoke(?string $path = null): Response
    {
        $shell = @file_get_contents(self::shellPath());
        if ($shell === false) {
            error_log('[web] resources/web/shell.html is missing; build the pages with `bun run build` in frontend/');

            return new Response(self::errorPage(), 500, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $address = '/'.trim((string) $path, '/');

        return new Response($shell, preg_match(self::PAGES, $address) === 1 ? 200 : 404, [
            'Content-Type' => 'text/html; charset=utf-8',
            // The shell names the hashed asset files of one build, so it is never cached.
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** The plain page shown when a page cannot be served at all (port of src/lib/error-page.ts). */
    public static function errorPage(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <title>This page didn't load</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
      body { font: 15px/1.5 system-ui, -apple-system, sans-serif; background: #fafafa; color: #111; display: grid; place-items: center; min-height: 100vh; margin: 0; padding: 1.5rem; }
      .card { max-width: 28rem; width: 100%; text-align: center; padding: 2rem; }
      h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
      p { color: #4b5563; margin: 0 0 1.5rem; }
      .actions { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }
      a, button { padding: 0.5rem 1rem; border-radius: 0.375rem; font: inherit; cursor: pointer; text-decoration: none; border: 1px solid transparent; }
      .primary { background: #111; color: #fff; }
      .secondary { background: #fff; color: #111; border-color: #d1d5db; }
    </style>
  </head>
  <body>
    <div class="card">
      <h1>This page didn't load</h1>
      <p>Something went wrong on our end. You can try refreshing or head back home.</p>
      <div class="actions">
        <button class="primary" onclick="location.reload()">Try again</button>
        <a class="secondary" href="/">Go home</a>
      </div>
    </div>
  </body>
</html>
HTML;
    }
}

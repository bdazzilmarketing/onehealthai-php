# Deploying to Cloudways

The application is a standard Laravel app: PHP 8.3, Apache/Nginx with the web
root at `public/`, and a PostgreSQL database reached over the network (Neon).
Nothing has to be built on the server: the web pages are committed already
built (`public/assets`, `resources/web/shell.html`).

Steps marked **check** have not been tried on a Cloudways server yet.

## 1. Database (Neon)

1. Create a Neon project (PostgreSQL 15 or newer) in a region close to the
   Cloudways server. Note the connection string of the owner role.
2. From any machine with this repository and PHP (the Cloudways server works):

   ```sh
   MIGRATION_DATABASE_URL='postgres://<owner>:<password>@<host>/<db>?sslmode=require' php artisan onehealth:migrate
   psql "$MIGRATION_DATABASE_URL" -v login=onehealth_app -v password='<a new strong password>' \
     -f database/sql/scripts/create-app-login.sql
   ```

   The first command creates the schema, roles and row-level security exactly
   as in the original application. The second creates the login the web
   application uses; it has no privileges of its own.
3. The web application's `DATABASE_URL` uses `onehealth_app`, never the owner.
   Keep `MIGRATION_DATABASE_URL` out of the server's `.env`.

## 2. Cloudways application

1. Create a **Laravel** application. In *Server → Settings & Packages* choose
   PHP 8.3. **Check** that `php -m` lists `pdo_pgsql`; if it does not, ask
   Cloudways support to enable it (the application cannot run without it).
2. *Application → Deployment via Git*: add the deploy key to the GitHub
   repository, branch `main`, deployment path empty (the repository root goes
   to `public_html`). The Laravel web root `public_html/public` is the default.
3. Over SSH in `public_html`:

   ```sh
   composer install --no-dev --optimize-autoloader
   cp .env.example .env    # then fill it in (next section)
   php artisan key:generate
   php artisan config:cache && php artisan route:cache
   ```

   Run the last line again after every deploy or `.env` change.
4. *Application → Application Settings*: turn **Varnish off** for this
   application. Pages and API answers depend on cookies and must not be cached.
5. *Server → Settings & Packages → Basic*: set the execution limit to at
   least 120 seconds. One AI turn can take close to a minute.
6. Enable the free SSL certificate and force HTTPS. Session cookies are
   `Secure` and only work over HTTPS.

## 3. Settings (`.env`)

`.env.example` lists every setting with a comment. The ones that must be set:

| Setting | Value |
| --- | --- |
| `APP_URL`, `PUBLIC_SITE_URL`, `ADMIN_SITE_URL` | The site address, for example `https://onehealthai.bdazzil.dev` |
| `DATABASE_URL` | `postgres://onehealth_app:<password>@<neon host>/<db>`, with `DB_SSLMODE=require` |
| `AUTH_SECRET` | 32+ random characters. Changing it later signs everyone out |
| `AI_QUOTA_HASH_SECRET` | 32+ random characters |
| `CRON_SECRET` | 32+ random characters |
| `FILE_STORE_DRIVER=local`, `FILE_STORE_PATH` | A folder outside `public_html` that deploys do not touch, for example `/home/master/applications/<app>/private_html/onehealth-files` |
| `EMAIL_DRIVER` and its `SMTP_*` or `GRAPH_*` values | See the README. On staging also set `EMAIL_ALLOWED_RECIPIENTS` |
| `OPENAI_API_KEY` | Without it the assistant reports itself unavailable; Manual Mode works |
| `TRUSTED_PROXY_MODE` | Start with `none` (all visitors share one rate limit). **Check** which address PHP sees behind Cloudways' Nginx before switching to `header` with `CLIENT_IP_HEADER=x-forwarded-for` and `TRUSTED_PROXY_CIDRS=127.0.0.1/32` |

Generate secrets on the server with `openssl rand -base64 48`.

## 4. Scheduled jobs

*Application → Cron Job Management*, two jobs of type `curl`:

```cron
*/5 * * * * curl -fsS -m 120 -H "Authorization: Bearer <CRON_SECRET>" https://<site>/api/cron/sales-report-recovery >/dev/null
7 * * * *   curl -fsS -m 120 -H "Authorization: Bearer <CRON_SECRET>" https://<site>/api/cron/maintenance >/dev/null
```

## 5. First account

```sh
php artisan onehealth:bootstrap-super-admin you@example.com --create
```

prints a single-use activation link (valid 72 hours). Opening it sets the
password; the portal then asks for two-step sign-in set-up. The command
refuses once a Super Admin can sign in. Further accounts are created in
*User Management*. Datasets are loaded by uploading the Excel workbooks in
*Data Management*.

## 6. Checks after a deploy

- `https://<site>/api/health/ready` answers `{"status":"ready"}`.
- The home page, `/calculator` and `/adminpanel` load.
- *Admin Portal → Integrations* shows the configured services.
- Both cron URLs answer `{"ok":true,...}` with the secret and 404 without it.

## Updating

Pull the new commit (*Deployment via Git → Pull*), then over SSH:

```sh
composer install --no-dev --optimize-autoloader
MIGRATION_DATABASE_URL=... php artisan onehealth:migrate   # only when database/sql/migrations changed
php artisan config:cache && php artisan route:cache
```

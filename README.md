# One Health AI Lab Calculator (Laravel)

Laravel port of `bdazzilmarketing/onehealthai` (branch `migration/portable`).
The goal is the same application: same screens, same PostgreSQL schema and
row-level security, same behaviour.

## Status

| Part | State |
| --- | --- |
| PostgreSQL schema, roles, grants, 38 RLS policies, SQL functions | Done: the original SQL files, byte for byte (`database/sql/migrations`) |
| Migration runner (`php artisan onehealth:migrate`) | Done |
| Per-transaction user/service context (`App\Database\Db`) | Done |
| Staff login, two-step sign-in, sessions, role guards (`App\Auth`, `/api/auth/*`) | Done |
| Calculator, admin panel, Excel import | Not started |
| AI chat and cards | Not started |
| Reports, email, scheduled jobs | Not started |

## Database

The application connects as a `NOINHERIT` login with no privileges of its own
and reaches data only through `App\Database\Db`:

- `withUserContext($userId, $fn)`: `SET LOCAL ROLE onehealth_authenticated` and
  `app.user_id`, so row-level security and `auth.uid()` see that user.
- `withServiceContext($fn)`: `SET LOCAL ROLE onehealth_service` for trusted
  server work.

Do not use Eloquent or the `DB` facade for application data: a query outside
these helpers is refused by PostgreSQL.

```sh
# 1. Schema (migration login)
MIGRATION_DATABASE_URL=postgres://... php artisan onehealth:migrate
# 2. Runtime login, once per environment
psql "$MIGRATION_DATABASE_URL" -v login=onehealth_app -v password="$APP_DB_PASSWORD" -f database/sql/scripts/create-app-login.sql
```

Never edit an applied file in `database/sql/migrations`; add a new one.

## Staff authentication

`/api/auth/*` answers the same endpoints, cookies and JSON as the original
application, and reads and writes the same `auth` tables in the same formats
(session tokens, link tokens, encrypted authenticator secrets, backup codes,
rate limits). Only the endpoints the admin portal uses are reachable.

- Sessions are checked against the database on every request; routes use the
  `staff` middleware (or `staff:session` for the two-step setup screen) and
  read `$request->attributes->get('staff')` for the user and a database scope
  bound to that user.
- New and changed passwords are stored as Argon2id. A hash stored by the
  original application (scrypt) is still accepted and is replaced with
  Argon2id at that user's next sign-in.
- The caller's address (rate limiting, session records) comes only from the
  trusted resolver configured by `TRUSTED_PROXY_MODE`.

## Tests

```sh
php vendor/bin/phpunit
```

The database tests start a throwaway PostgreSQL cluster (`initdb` from
`/usr/lib/postgresql/16/bin`, or set `ONEHEALTH_PG_BIN`).

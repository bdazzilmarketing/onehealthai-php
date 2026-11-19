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
| Staff login, MFA, roles | Not started |
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

## Tests

```sh
php vendor/bin/phpunit
```

The database tests start a throwaway PostgreSQL cluster (`initdb` from
`/usr/lib/postgresql/16/bin`, or set `ONEHEALTH_PG_BIN`).

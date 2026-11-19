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
| Calculator: public catalog, pricing engine, lead sessions, submissions (`/api/fn/getCalculatorCatalog`, `/api/fn/calculateEstimate`) | Done |
| Admin panel: dashboard, Excel import, dataset versions and publishing, settings, AI prompt versions, audit log, submissions, sales recipients, AI limits (`/api/fn/*`) | Done |
| Private file storage (local or S3-compatible), signed report links | Done |
| AI assistant: chat, cards, scope guard, usage limits, handoff to the calculator (`/api/fn/sendAiMessage`, `getAiPicker`, `sendAiChoice`, `resetAiSession`, `finalizeAiSelection`, `getAiAvailability`) | Done |
| Reports (PDF), email, scheduled jobs | Not started. Until then account emails are not sent, and `regenerateSubmissionReport` / `resendSalesNotification` are not served |

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

## Calculator

`App\Calculator\CalculationEngine` is the single calculation implementation
(`equal_distribution_v1`). Prices are read server-side from the published
dataset and never appear in a response; the browser receives only the public
range. Arithmetic, rounding, number formatting and the stored JSON follow the
original exactly (`App\Support\Js`): `tests/Fixtures/calculator-parity.json`
holds results recorded from the original TypeScript code, and
`tests/Unit/CalculatorParityTest.php` requires the same bytes.

Each original server function `name` is served at `/api/fn/name` and answers
the same JSON. An error the original threw to the browser is answered as
`500 {"message": ...}`.

## Admin panel

`App\Http\Controllers\AdminController` serves the original admin server functions, one endpoint each, at `/api/fn/<name>` (GET for reads without input, POST with the input as the JSON body). Every endpoint sits behind the `staff` guard, checks the caller's role first (`App\Auth\Roles`), and then reads or writes in the caller's own database context, so row-level security decides again. Answers are the same JSON as before: numeric columns as numbers, timestamps as `2026-01-31T09:30:00.123+00:00`, JSON columns as objects (`Tx::rows`).

**Excel import.** `App\Import\XlsxReader` reads `.xlsx` files (sheets, shared strings, used range, cell values) with the same rules as the spreadsheet library of the original app, and `App\Import\WorkbookParser` is the port of its parser and validation engine. `tests/Fixtures/workbook-parser-parity.json` holds the output of the original parser for the workbooks in `tests/Fixtures/workbooks`; the PHP parser must produce the same bytes. Old-format `.xls` files are not accepted (decided 2026-11-19): they are stored as a failed version with the message "The workbook could not be read".

**File storage.** Uploaded workbooks (and later the internal report PDFs) go to private storage, never a public folder:

| Variable | Meaning |
| --- | --- |
| `FILE_STORE_DRIVER` | `local` or `s3`. Unset: uploads fail closed |
| `FILE_STORE_PATH` | `local`: directory outside the web root that survives deploys |
| `FILE_STORE_S3_ENDPOINT`, `FILE_STORE_S3_BUCKET`, `FILE_STORE_S3_REGION`, `FILE_STORE_S3_ACCESS_KEY_ID`, `FILE_STORE_S3_SECRET_ACCESS_KEY` | `s3`: any S3-compatible service, path-style, Signature Version 4 |

`MAKE_WEBHOOK_URL` and `MAKE_WEBHOOK_SECRET` are only used by the manual "resend to Make" action.

## AI assistant

`App\Ai` is a line-for-line port of the original assistant. A typed message passes, in this order:
input limits, the built-in scope rules, one quota reservation in the database
(`ai_reserve_quota`), a classifier call, the main call, validation of the structured answer
against the published catalog, a final check of the assembled reply and an independent
reviewer call. Any failure answers with a fixed safe message and nothing is saved. Card
clicks never call OpenAI. The assistant is never given a price and cannot return one.

| Variable | Meaning |
| --- | --- |
| `OPENAI_API_KEY` | Unset: the assistant reports itself unavailable and Manual Mode keeps working |
| `AI_QUOTA_HASH_SECRET` | At least 32 characters. Hashes visitor and network identifiers for the limits. Unset: the assistant fails closed |
| `AI_QA_DIAGNOSTICS_TOKEN`, `AI_QA_DIAGNOSTICS_EXPIRES_AT` | Optional content-free diagnostics for QA runs (ISO 8601 expiry, at most 72 hours ahead) |

The model and the system prompt come from the active prompt version in the admin panel.
The texts in `app/Ai/Data/generated.php` (rules, instructions, fixed replies) are copied
mechanically from the original and are not edited by hand.

`tests/Unit/AiParityTest.php` replays about 48,000 recorded checks from the original code
(messages, model answers, card walks, provider payloads) and requires identical results.

## Tests

```sh
php vendor/bin/phpunit
```

The database tests start a throwaway PostgreSQL cluster (`initdb` from
`/usr/lib/postgresql/16/bin`, or set `ONEHEALTH_PG_BIN`).

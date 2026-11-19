-- Creates the runtime login the application connects with (DATABASE_URL).
-- Run once per environment as the migration login, after db:migrate:
--   psql "$MIGRATION_DATABASE_URL" -v login=onehealth_app -v password="$APP_DB_PASSWORD" \
--        -f db/scripts/create-app-login.sql
-- Supply the password from the environment's secret store; never commit it.
--
-- NOINHERIT: the login holds no privileges of its own. src/lib/db.server.ts
-- switches each transaction to onehealth_authenticated or onehealth_service
-- with SET LOCAL ROLE, so a query outside those helpers is denied. The
-- authentication library's own pool connects with role=onehealth_auth.
\set ON_ERROR_STOP on
SELECT format('CREATE ROLE %I LOGIN NOINHERIT PASSWORD %L', :'login', :'password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'login') \gexec
SELECT format('ALTER ROLE %I LOGIN NOINHERIT PASSWORD %L', :'login', :'password') \gexec
SELECT format('GRANT onehealth_authenticated, onehealth_service, onehealth_auth TO %I', :'login') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'login') \gexec

-- Portable identity anchor replacing Supabase's auth schema.
-- auth.users keeps the existing user UUIDs so the nine foreign keys from the
-- application tables (user_roles, profiles, audit_log, ...) stay valid. The auth
-- library's own tables (sessions, credentials, MFA) arrive with the auth phase.
CREATE SCHEMA auth;

CREATE TABLE auth.users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email text NOT NULL,
  email_verified_at timestamptz,
  disabled_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT users_email_normalized CHECK (email = lower(btrim(email)) AND email <> '')
);
CREATE UNIQUE INDEX users_email_key ON auth.users (email);
ALTER TABLE auth.users ENABLE ROW LEVEL SECURITY;

-- The signed-in user of the current transaction, or NULL. The database access
-- layer (src/lib/db.server.ts) sets app.user_id with set_config(..., true), so
-- the value ends with the transaction and cannot leak to the next request that
-- reuses the pooled connection. Same contract as Supabase's auth.uid(), so the
-- row-level security policies and functions use it unchanged. An unparsable
-- value raises an error instead of matching rows (fail closed).
CREATE FUNCTION auth.uid() RETURNS uuid
  LANGUAGE sql STABLE PARALLEL SAFE
  AS $$ SELECT nullif(current_setting('app.user_id', true), '')::uuid $$;

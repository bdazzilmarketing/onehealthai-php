-- Tables for the self-hosted authentication library (Better Auth), in the auth
-- schema next to auth.users. Column names are snake_case; the mapping to the
-- library's field names lives in src/lib/auth/auth.server.ts, and the library
-- verifies this schema against its own expectations on first use.
--
-- auth.users keeps the existing user UUIDs. Better Auth's user model maps onto
-- it with three new columns; email_verified_at is replaced by the boolean the
-- library uses.
ALTER TABLE auth.users
  ADD COLUMN name text NOT NULL DEFAULT '',
  ADD COLUMN email_verified boolean NOT NULL DEFAULT false,
  ADD COLUMN image text,
  ADD COLUMN two_factor_enabled boolean DEFAULT false;
ALTER TABLE auth.users DROP COLUMN email_verified_at;

-- Server-side sessions; the browser holds only an HttpOnly cookie with the token.
CREATE TABLE auth.sessions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES auth.users (id) ON DELETE CASCADE,
  token text NOT NULL UNIQUE,
  expires_at timestamptz NOT NULL,
  ip_address text,
  user_agent text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sessions_user_id_idx ON auth.sessions (user_id);

-- Sign-in methods. provider_id 'credential' holds the password hash; Entra ID
-- would add a 'microsoft' row later. Hashes from Supabase are never imported.
CREATE TABLE auth.accounts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES auth.users (id) ON DELETE CASCADE,
  account_id text NOT NULL,
  provider_id text NOT NULL,
  access_token text,
  refresh_token text,
  id_token text,
  access_token_expires_at timestamptz,
  refresh_token_expires_at timestamptz,
  scope text,
  password text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX accounts_user_id_idx ON auth.accounts (user_id);
CREATE UNIQUE INDEX accounts_provider_account_key ON auth.accounts (provider_id, account_id);

-- Single-use tokens (activation and password links).
CREATE TABLE auth.verifications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  identifier text NOT NULL,
  value text NOT NULL,
  expires_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX verifications_identifier_idx ON auth.verifications (identifier);

-- TOTP secrets and hashed backup codes (encrypted by the library with AUTH_SECRET).
CREATE TABLE auth.two_factors (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES auth.users (id) ON DELETE CASCADE,
  secret text NOT NULL,
  backup_codes text NOT NULL,
  verified boolean DEFAULT true,
  failed_verification_count integer DEFAULT 0,
  locked_until timestamptz
);
CREATE INDEX two_factors_user_id_idx ON auth.two_factors (user_id);
CREATE INDEX two_factors_secret_idx ON auth.two_factors (secret);

-- Sign-in rate limiting shared by every server instance.
CREATE TABLE auth.rate_limits (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  key text NOT NULL UNIQUE,
  count integer NOT NULL,
  last_request bigint NOT NULL
);

-- Least privilege for the library's role. It cannot create users (sign-up is
-- off; staff accounts are created by a Super Admin through the service role),
-- cannot change ids, emails or the disabled flag, and sees no application data.
GRANT USAGE ON SCHEMA auth TO onehealth_auth;
GRANT SELECT ON auth.users TO onehealth_auth;
GRANT UPDATE (name, email_verified, image, two_factor_enabled, updated_at) ON auth.users TO onehealth_auth;
GRANT SELECT, INSERT, UPDATE, DELETE ON
  auth.sessions, auth.accounts, auth.verifications, auth.two_factors, auth.rate_limits
  TO onehealth_auth;
CREATE POLICY "Auth library reads and updates identities" ON auth.users
  TO onehealth_auth USING (true) WITH CHECK (true);
-- Row-level security stays on for every table (defence in depth): only the
-- library's role has a policy, and the service role bypasses RLS.
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['sessions', 'accounts', 'verifications', 'two_factors', 'rate_limits'] LOOP
    EXECUTE format('ALTER TABLE auth.%I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY "Auth library only" ON auth.%I TO onehealth_auth USING (true) WITH CHECK (true)', t);
  END LOOP;
END $$;

-- Server-side user administration (service role): disable accounts and revoke
-- their sessions, issue activation and reset tokens, see whether an account has
-- a password. It never reads password hashes or MFA secrets.
GRANT SELECT, DELETE ON auth.sessions TO onehealth_service;
GRANT SELECT (id, user_id, provider_id, created_at) ON auth.accounts TO onehealth_service;
GRANT SELECT, INSERT, DELETE ON auth.verifications TO onehealth_service;

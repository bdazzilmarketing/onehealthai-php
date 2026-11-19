-- Group role for the authentication library (Better Auth). Runs as the
-- migration login, like 0000.
--
--   onehealth_auth  reads and writes only the auth schema tables the library
--                   owns (sessions, credentials, verification tokens, MFA,
--                   rate limits) and the identity columns of auth.users. It has
--                   no access to application data.
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'onehealth_auth') THEN
    CREATE ROLE onehealth_auth NOLOGIN;
  END IF;
END $$;

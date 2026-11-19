-- Group roles for the One Health database. Runs as the connecting migration
-- login (the database owner or a CREATEROLE admin); every later migration runs
-- as onehealth_owner. Login roles are environment-specific and are created
-- separately (db/scripts/create-app-login.sql), never with a password here.
--
--   onehealth_owner          owns every object; SECURITY DEFINER functions run as it.
--   onehealth_authenticated  a signed-in staff request; row-level security applies
--                            (replaces Supabase `authenticated`).
--   onehealth_service        trusted server work; bypasses row-level security
--                            (replaces Supabase `service_role`).
-- Supabase's `anon` role has no counterpart: it had no row-level security
-- policy anywhere, so it never had effective access to application data.
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'onehealth_owner') THEN
    CREATE ROLE onehealth_owner NOLOGIN;
  END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'onehealth_authenticated') THEN
    CREATE ROLE onehealth_authenticated NOLOGIN;
  END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'onehealth_service') THEN
    CREATE ROLE onehealth_service NOLOGIN BYPASSRLS;
  END IF;
  -- The migration login switches to onehealth_owner for every later file. From
  -- PostgreSQL 16 a CREATEROLE login that creates a role holds only ADMIN on it
  -- (no SET), as on Neon, so the SET option is granted explicitly.
  IF current_setting('server_version_num')::int >= 160000 THEN
    IF NOT pg_has_role(current_user, 'onehealth_owner', 'SET') THEN
      EXECUTE format('GRANT onehealth_owner TO %I WITH SET TRUE', current_user);
    END IF;
  ELSIF NOT pg_has_role(current_user, 'onehealth_owner', 'MEMBER') THEN
    EXECUTE format('GRANT onehealth_owner TO %I', current_user);
  END IF;
  EXECUTE format('GRANT CREATE, CONNECT ON DATABASE %I TO onehealth_owner', current_database());
END $$;

GRANT USAGE, CREATE ON SCHEMA public TO onehealth_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

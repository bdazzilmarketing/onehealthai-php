-- Creates (or promotes) the first super admin of a new environment. Run as the
-- migration login after db:migrate:
--   psql "$MIGRATION_DATABASE_URL" -v email=someone@example.com \
--        -f db/scripts/bootstrap-super-admin.sql
-- No password is set here: issue the one-off activation link afterwards with
--   node scripts/auth/bootstrap-super-admin.ts someone@example.com
-- A migrated environment imports its users and roles and only needs the link.
\set ON_ERROR_STOP on
BEGIN;
SET LOCAL ROLE onehealth_owner;
WITH input AS (SELECT lower(btrim(:'email')) AS email),
     u AS (
       INSERT INTO auth.users (email) SELECT email FROM input
       ON CONFLICT (email) DO UPDATE SET updated_at = now()
       RETURNING id, email
     ),
     p AS (
       INSERT INTO public.profiles (id, email) SELECT id, email FROM u
       ON CONFLICT (id) DO NOTHING
     )
INSERT INTO public.user_roles (user_id, role)
SELECT id, 'super_admin'::public.app_role FROM u
ON CONFLICT DO NOTHING;
COMMIT;

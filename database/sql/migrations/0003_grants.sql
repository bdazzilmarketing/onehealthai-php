-- Explicit, least-privilege grants. Supabase granted ALL (including TRUNCATE,
-- which ignores row-level security) on every public table to anon,
-- authenticated and service_role and left RLS as the only gate. Here each role
-- gets exactly the commands its policies can allow, so effective access is
-- unchanged while the table privileges no longer exceed it.

GRANT USAGE ON SCHEMA public, auth TO onehealth_authenticated, onehealth_service;
-- private holds no objects after the portable baseline; nobody but the owner uses it.
REVOKE ALL ON SCHEMA private FROM PUBLIC;

-- Functions: nothing is callable by PUBLIC unless granted below.
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA public, auth FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE onehealth_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

GRANT EXECUTE ON FUNCTION auth.uid() TO onehealth_authenticated, onehealth_service;
-- Role helpers used inside policies and by the admin authorization checks.
GRANT EXECUTE ON FUNCTION
  public.has_role(uuid, public.app_role),
  public.is_super_admin(uuid),
  public.is_security_auditor(uuid),
  public.can_view_admin(uuid),
  public.publish_dataset_version(uuid),
  public.activate_ai_prompt(uuid)
  TO onehealth_authenticated, onehealth_service;
-- Finding 4301: quota reservation is server-only, never callable from a user context.
GRANT EXECUTE ON FUNCTION
  public.ai_reserve_quota(text, text, integer),
  public.ai_reserve_quota(text, text, integer, text, text),
  public.ai_release_quota(uuid, integer),
  public.ai_quota_cleanup()
  TO onehealth_service;
GRANT EXECUTE ON FUNCTION public.protect_last_super_admin(), public.update_updated_at_column()
  TO onehealth_authenticated, onehealth_service;

-- Trusted server work (row-level security bypassed, as with service_role).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO onehealth_service;
GRANT SELECT, INSERT, UPDATE, DELETE ON auth.users TO onehealth_service;

-- Signed-in staff: only the commands that some policy for the role permits.
GRANT SELECT ON
  public.ai_global_usage,
  public.ai_usage_events,
  public.audit_log,
  public.user_roles
  TO onehealth_authenticated;
GRANT SELECT, UPDATE ON public.ai_quota_settings TO onehealth_authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON
  public.ai_prompt_versions,
  public.dataset_versions,
  public.hardware,
  public.hardware_categories,
  public.laboratories,
  public.parameter_groups,
  public.parameters,
  public.profiles,
  public.sales_report_recipients,
  public.settings,
  public.sub_labs,
  public.uploaded_files
  TO onehealth_authenticated;
-- No grants for onehealth_authenticated on ai_conversations, ai_inflight,
-- ai_rate_buckets, calculator_sessions, calculator_submissions or auth.users:
-- no policy ever admitted that role there.

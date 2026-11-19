-- A super admin can do everything an admin can. The application already treats
-- super_admin as admin (isUserAdmin), and a few policies and activate_ai_prompt
-- already accept either role, but the catalog, settings, audit and prompt
-- policies and publish_dataset_version checked has_role('admin') only. A super
-- admin without the separate admin role could open the catalog import screen
-- and then fail with "Unable to create the draft dataset version".
-- Same policy names and commands; only the role check widens to super_admin.

DROP POLICY "Admins can manage settings" ON public.settings;
CREATE POLICY "Admins can manage settings" ON public.settings TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins can read audit log" ON public.audit_log;
CREATE POLICY "Admins can read audit log" ON public.audit_log FOR SELECT TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage ai prompts" ON public.ai_prompt_versions;
CREATE POLICY "Admins manage ai prompts" ON public.ai_prompt_versions TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage dataset versions" ON public.dataset_versions;
CREATE POLICY "Admins manage dataset versions" ON public.dataset_versions TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage hardware" ON public.hardware;
CREATE POLICY "Admins manage hardware" ON public.hardware TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage hardware_categories" ON public.hardware_categories;
CREATE POLICY "Admins manage hardware_categories" ON public.hardware_categories TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage laboratories" ON public.laboratories;
CREATE POLICY "Admins manage laboratories" ON public.laboratories TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage parameter_groups" ON public.parameter_groups;
CREATE POLICY "Admins manage parameter_groups" ON public.parameter_groups TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage parameters" ON public.parameters;
CREATE POLICY "Admins manage parameters" ON public.parameters TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage sub_labs" ON public.sub_labs;
CREATE POLICY "Admins manage sub_labs" ON public.sub_labs TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

DROP POLICY "Admins manage uploaded files" ON public.uploaded_files;
CREATE POLICY "Admins manage uploaded files" ON public.uploaded_files TO onehealth_authenticated USING ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid()))) WITH CHECK ((public.has_role(auth.uid(), 'admin'::public.app_role) OR public.is_super_admin(auth.uid())));

-- FUNCTION: publish_dataset_version(uuid), unchanged apart from the role check.
CREATE OR REPLACE FUNCTION public.publish_dataset_version(_version_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
declare
  _type public.workbook_type;
  _status public.dataset_status;
  _errors integer;
begin
  if not (public.has_role(auth.uid(), 'admin') or public.is_super_admin(auth.uid())) then
    raise exception 'Forbidden: admin role required';
  end if;

  select workbook_type, status,
         coalesce((validation_summary->>'criticalErrors')::int, 0)
    into _type, _status, _errors
  from public.dataset_versions where id = _version_id for update;

  if _type is null then
    raise exception 'Dataset version not found';
  end if;
  if _status not in ('validated','archived') then
    raise exception 'Only validated or archived versions can be published';
  end if;
  if _errors > 0 then
    raise exception 'Dataset has critical validation errors';
  end if;

  update public.dataset_versions
     set status = 'archived', superseded_at = now(), updated_at = now()
   where workbook_type = _type and status = 'published';

  update public.dataset_versions
     set status = 'published', published_at = now(), published_by = auth.uid(), updated_at = now()
   where id = _version_id;
end;
$$;

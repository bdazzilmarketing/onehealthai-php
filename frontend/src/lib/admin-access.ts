import { useQuery } from "@tanstack/react-query";
import { useServerFn } from "@tanstack/react-start";

import { getSession } from "@/lib/admin.functions";

/**
 * Reads the current admin session (shared ["admin-session"] cache).
 * UI hint only — every mutation is also enforced on the server and by RLS.
 */
export function useAdminAccess() {
  const fetchSession = useServerFn(getSession);
  const { data } = useQuery({ queryKey: ["admin-session"], queryFn: () => fetchSession() });
  return {
    readOnly: data?.readOnly ?? true,
    superAdmin: data?.superAdmin ?? false,
  };
}

import { useQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { getAdminSettings } from "@/lib/admin.functions";

export const Route = createFileRoute("/adminpanel/integrations")({
  component: Integrations,
});

function Integrations() {
  const fetchSettings = useServerFn(getAdminSettings);
  const { data, isLoading } = useQuery({
    queryKey: ["settings"],
    queryFn: () => fetchSettings(),
    // Integration status reflects live server-side secrets, so never serve it stale.
    staleTime: 0,
    refetchOnMount: "always",
    refetchOnWindowFocus: true,
  });

  if (isLoading || !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Integrations</h1>
        <p className="text-sm text-muted-foreground">
          Status only. Secrets live in server-side environment configuration and are never returned
          to the browser.
        </p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        {data.integrations.map((integration) => (
          <Card key={integration.key}>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">{integration.label}</CardTitle>
              <CardDescription>{integration.note}</CardDescription>
            </CardHeader>
            <CardContent>
              <Badge variant={integration.configured ? "default" : "secondary"}>
                {integration.configured ? "Configured" : "Not configured"}
              </Badge>
            </CardContent>
          </Card>
        ))}
      </div>

      <p className="text-xs text-muted-foreground">
        OpenAI calls, sales report emails and report storage run server-side only. Keys and secrets
        are infrastructure-level configuration: no interface reads them back or writes them.
        Make.com is no longer used for new submissions; historical delivery records are kept on
        each submission for audit.
      </p>
    </div>
  );
}

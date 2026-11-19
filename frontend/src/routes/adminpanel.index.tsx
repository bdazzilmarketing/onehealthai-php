import { useQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { useAdminAccess } from "@/lib/admin-access";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { getDashboard } from "@/lib/admin.functions";
import { WORKBOOK_TYPES } from "@/lib/workbook-types";

export const Route = createFileRoute("/adminpanel/")({
  component: Dashboard,
});

function Dashboard() {
  const { readOnly } = useAdminAccess();
  const fetchDashboard = useServerFn(getDashboard);
  const { data, isLoading } = useQuery({
    queryKey: ["admin-dashboard"],
    queryFn: () => fetchDashboard(),
  });

  if (isLoading || !data) return <Skeleton className="h-96 w-full" />;

  const variance = data.settings.find((s) => s.key === "calculator_variance")?.value as
    { lower_percent?: number; upper_percent?: number } | undefined;
  const lastUpload = data.versions[0];
  const openai = data.integrations.find((i) => i.key === "openai");

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Dashboard</h1>
          <p className="text-sm text-muted-foreground">
            Operational status of published laboratory datasets.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button asChild size="sm">
            <Link to="/adminpanel/data">{readOnly ? "Data management" : "Upload dataset"}</Link>
          </Button>
          <Button asChild size="sm" variant="outline">
            <Link to="/adminpanel/published">View published data</Link>
          </Button>
          <Button asChild size="sm" variant="outline">
            <Link to="/adminpanel/history">Dataset history</Link>
          </Button>
          <Button asChild size="sm" variant="outline">
            <Link to="/adminpanel/ai">AI configuration</Link>
          </Button>
          <Button asChild size="sm" variant="outline">
            <Link to="/adminpanel/settings">Calculator settings</Link>
          </Button>
        </div>
      </div>

      <section className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {WORKBOOK_TYPES.map((type) => {
          const published = data.published.find((p) => p.workbook_type === type.value);
          const summary = (published?.validation_summary ?? {}) as Record<string, number>;
          return (
            <Card key={type.value}>
              <CardHeader className="pb-2">
                <CardTitle className="text-base">{type.label}</CardTitle>
                <CardDescription>
                  {published
                    ? `Version ${published.version_number} · ${published.original_filename}`
                    : "No published dataset"}
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                {published ? (
                  <>
                    <Badge>Published</Badge>
                    <p className="text-muted-foreground">
                      Published {new Date(published.published_at ?? "").toLocaleString()}
                    </p>
                    <p className="text-muted-foreground">
                      {summary["parameters"] ?? 0} parameters · {summary["hardware"] ?? 0} hardware
                      · {summary["parameterGroups"] ?? 0} groups
                    </p>
                  </>
                ) : (
                  <Badge variant="secondary">Not published</Badge>
                )}
              </CardContent>
            </Card>
          );
        })}
      </section>

      <section className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Last upload</CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            {lastUpload ? (
              <div className="space-y-1">
                <p className="font-medium">{lastUpload.original_filename}</p>
                <p className="text-muted-foreground">
                  {lastUpload.workbook_type} · v{lastUpload.version_number} ·{" "}
                  {new Date(lastUpload.uploaded_at).toLocaleString()}
                </p>
                <Badge variant={lastUpload.status === "validated" ? "default" : "secondary"}>
                  {lastUpload.status}
                </Badge>
              </div>
            ) : (
              <p className="text-muted-foreground">No uploads yet.</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Configuration</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>
              OpenAI:{" "}
              <Badge variant={openai?.configured ? "default" : "secondary"}>
                {openai?.configured ? "Configured" : "Not configured"}
              </Badge>
            </p>
            <p className="text-muted-foreground">
              Active AI prompt: v{data.activePrompt?.version_number ?? "—"} (
              {data.activePrompt?.model ?? "no model"})
            </p>
            <p className="text-muted-foreground">
              Estimate variance: −{variance?.lower_percent ?? 20}% / +
              {variance?.upper_percent ?? 20}%
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Recent audit activity</CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-xs">
            {data.audit.length === 0 && <p className="text-muted-foreground">No events yet.</p>}
            {data.audit.map((event) => (
              <p key={event.id} className="text-muted-foreground">
                <span className="font-mono">{event.event_type}</span> ·{" "}
                {new Date(event.created_at).toLocaleString()}
              </p>
            ))}
          </CardContent>
        </Card>
      </section>
    </div>
  );
}

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useServerFn } from "@tanstack/react-start";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { getAiQuotaOverview, updateAiQuotaSettings } from "@/lib/admin.functions";

const FIELDS: { key: string; label: string }[] = [
  { key: "visitor_per_minute", label: "Per visitor / minute" },
  { key: "visitor_per_hour", label: "Per visitor / hour" },
  { key: "visitor_per_day", label: "Per visitor / day" },
  { key: "ip_per_minute", label: "Per network (IP) / minute" },
  { key: "ip_per_hour", label: "Per network (IP) / hour" },
  { key: "ip_per_day", label: "Per network (IP) / day" },
  { key: "concurrent_per_visitor", label: "Concurrent per visitor" },
  { key: "concurrent_per_ip", label: "Concurrent per network" },
  { key: "global_requests_per_day", label: "Global requests / day" },
  { key: "global_tokens_per_day", label: "Global token budget / day (estimated)" },
  { key: "alert_threshold_1", label: "Budget alert 1 (%)" },
  { key: "alert_threshold_2", label: "Budget alert 2 (%)" },
  { key: "max_input_chars", label: "Max message length (chars)" },
  { key: "max_output_tokens", label: "Max output tokens / request" },
  { key: "max_turns", label: "Max conversation turns" },
];

const ALERT_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  ok: "secondary",
  warning: "outline",
  critical: "destructive",
  exceeded: "destructive",
};

export function AiQuotaPanel({ readOnly }: { readOnly: boolean }) {
  const queryClient = useQueryClient();
  const fetchOverview = useServerFn(getAiQuotaOverview);
  const save = useServerFn(updateAiQuotaSettings);
  const query = useQuery({ queryKey: ["ai-quota"], queryFn: () => fetchOverview(), refetchInterval: 30000 });
  const [form, setForm] = useState<Record<string, number>>({});
  const [enabled, setEnabled] = useState(true);

  useEffect(() => {
    if (!query.data) return;
    const s = query.data.settings as unknown as Record<string, number | boolean>;
    const next: Record<string, number> = {};
    for (const f of FIELDS) next[f.key] = Number(s[f.key]);
    setForm(next);
    setEnabled(Boolean(s["enabled"]));
  }, [query.data]);

  const mutation = useMutation({
    mutationFn: () => save({ data: { ...(form as Record<string, number>), enabled } as never }),
    onSuccess: () => {
      toast.success("AI usage limits saved");
      queryClient.invalidateQueries({ queryKey: ["ai-quota"] });
    },
    onError: () => toast.error("Could not save the limits. Check the values and try again."),
  });

  if (query.isLoading) return <Skeleton className="h-48 w-full" />;
  if (!query.data) return null;
  const { usage, rejectedLast24h, recentEvents } = query.data;

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">AI usage today (UTC)</CardTitle>
          <CardDescription>
            Token figures are estimates used for an application-level ceiling, not an exact
            financial spending cap.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 text-sm md:grid-cols-5">
          <Stat label="Requests" value={`${usage.requests} (${usage.requestPct}%)`} />
          <Stat label="Tokens charged" value={`${Number(usage.reserved_tokens).toLocaleString()} (${usage.tokenPct}%)`} />
          <Stat label="Actual tokens reported" value={Number(usage.actual_tokens).toLocaleString()} />
          <Stat label="Rejected" value={String(usage.rejected)} />
          <div>
            <p className="text-xs text-muted-foreground">Budget alert</p>
            <Badge variant={ALERT_VARIANT[usage.alert] ?? "secondary"}>{usage.alert}</Badge>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Usage limits</CardTitle>
          <CardDescription>
            {readOnly ? "Read-only view." : "Changes apply to the next AI request."}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-3">
            <Switch checked={enabled} disabled={readOnly} onCheckedChange={setEnabled} id="ai-enabled" />
            <Label htmlFor="ai-enabled">AI assistant enabled</Label>
          </div>
          <div className="grid gap-4 md:grid-cols-3">
            {FIELDS.map((f) => (
              <div key={f.key} className="space-y-1">
                <Label htmlFor={f.key} className="text-xs">{f.label}</Label>
                <Input
                  id={f.key}
                  type="number"
                  disabled={readOnly}
                  value={Number.isFinite(form[f.key]) ? form[f.key] : ""}
                  onChange={(e) => setForm((p) => ({ ...p, [f.key]: Number(e.target.value) }))}
                />
              </div>
            ))}
          </div>
          {!readOnly && (
            <Button disabled={mutation.isPending} onClick={() => mutation.mutate()}>
              Save limits
            </Button>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Rejected requests (last 24 hours)</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2 text-sm">
          {Object.keys(rejectedLast24h).length === 0 && <span className="text-muted-foreground">None</span>}
          {Object.entries(rejectedLast24h).map(([k, v]) => (
            <Badge key={k} variant="outline">{k}: {v}</Badge>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Recent limit and error events</CardTitle>
          <CardDescription>Visitors are shown as anonymised hashes only.</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="max-h-80 overflow-auto text-xs">
            <table className="w-full">
              <thead className="text-left text-muted-foreground">
                <tr><th className="py-1">Time</th><th>Visitor</th><th>Outcome</th><th>Limit / error</th><th>Est. tokens</th></tr>
              </thead>
              <tbody>
                {recentEvents.map((e) => (
                  <tr key={e.id} className="border-t">
                    <td className="py-1">{new Date(e.created_at).toLocaleString()}</td>
                    <td className="font-mono">{e.visitor_hash ?? "—"}</td>
                    <td>{e.outcome}</td>
                    <td>{e.limit_triggered ?? e.error_category ?? "—"}</td>
                    <td>{e.estimated_tokens ?? "—"}</td>
                  </tr>
                ))}
                {recentEvents.length === 0 && (
                  <tr><td colSpan={5} className="py-2 text-muted-foreground">No events</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="font-medium">{value}</p>
    </div>
  );
}

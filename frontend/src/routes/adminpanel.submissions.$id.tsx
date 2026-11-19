import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { useAdminAccess } from "@/lib/admin-access";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  getCalculatorSubmission,
  getSubmissionReportUrl,
  regenerateSubmissionReport,
  resendSalesNotification,
  retryMakeDelivery,
} from "@/lib/admin.functions";

export const Route = createFileRoute("/adminpanel/submissions/$id")({
  component: SubmissionDetail,
});

interface SnapshotParameter {
  parameterName: string;
  pricePerTest: number;
  allocatedAnnualVolume: number;
  annualCalculatedCost: number;
}
interface SnapshotHardware {
  hardwareName: string;
  price: number;
}
interface SnapshotSection {
  subLabName: string;
  annualTestVolume: number;
  hardware: SnapshotHardware[];
  parameters: SnapshotParameter[];
  hardwareSubtotal: number;
  parameterSubtotal: number;
  subtotal: number;
}
interface SnapshotLab {
  labName: string;
  subtotal: number;
  subLabs: SnapshotSection[];
}
interface InternalSnapshot {
  currency: string;
  internalTotal: number;
  calculationVersion: string;
  datasetVersions: Record<string, number>;
  labs: SnapshotLab[];
}

function money(amount: number, currency: string) {
  return `${currency} ${Number(amount).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
}

function SubmissionDetail() {
  const { id } = Route.useParams();
  const queryClient = useQueryClient();
  const fetchSubmission = useServerFn(getCalculatorSubmission);
  const retry = useServerFn(retryMakeDelivery);
  const { readOnly } = useAdminAccess();

  const { data, isLoading } = useQuery({
    queryKey: ["submission", id],
    queryFn: () => fetchSubmission({ data: { id } }),
  });

  const retryMutation = useMutation({
    mutationFn: () => retry({ data: { id } }),
    onSuccess: (result) => {
      if (result.status === "sent") toast.success("Delivered to Make.com");
      else toast.error(result.error ?? "Delivery failed");
      void queryClient.invalidateQueries({ queryKey: ["submission", id] });
      void queryClient.invalidateQueries({ queryKey: ["submissions"] });
    },
    onError: () => toast.error("Retry could not be started"),
  });

  if (isLoading) return <Skeleton className="h-96 w-full" />;
  if (!data) return <p className="text-sm text-muted-foreground">Submission not found.</p>;

  const snapshot = data.internal_calculation_snapshot as unknown as InternalSnapshot;
  const currency = data.currency;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-mono text-2xl font-semibold">{data.reference_id}</h1>
          <p className="text-sm text-muted-foreground">
            {new Date(data.created_at).toLocaleString()} ·{" "}
            {String(data.selected_mode).toUpperCase()} mode
          </p>
        </div>
        <Link to="/adminpanel/submissions" className="text-sm underline">
          Back to submissions
        </Link>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Lead</CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-sm">
            <p>{data.full_name}</p>
            <p className="text-muted-foreground">{data.company}</p>
            <p className="text-muted-foreground">{data.email}</p>
            <p className="text-muted-foreground">{data.phone}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Pricing</CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-sm">
            <p>
              Internal total:{" "}
              <span className="font-semibold">{money(Number(data.internal_total), currency)}</span>
            </p>
            <p className="text-muted-foreground">
              Public range shown to visitor: {money(Number(data.public_low), currency)} –{" "}
              {money(Number(data.public_high), currency)}
            </p>
            <p className="text-xs text-muted-foreground">
              Engine version {data.calculation_version} · dataset versions{" "}
              {Object.entries((data.dataset_versions ?? {}) as Record<string, number>)
                .map(([k, v]) => `${k} v${v}`)
                .join(", ") || "—"}
            </p>
          </CardContent>
        </Card>
      </div>

      <SalesReportCard id={id} data={data} readOnly={readOnly} />

      {/* Historical Make.com record — new submissions no longer use Make. */}
      {data.make_delivery_status !== "disabled" && (
        <>
      <Card>
        <CardHeader className="flex flex-row items-center justify-between pb-2">
          <CardTitle className="text-base">Make.com delivery (legacy)</CardTitle>
          {!readOnly && (
            <Button
              size="sm"
              variant="outline"
              disabled={retryMutation.isPending}
              onClick={() => retryMutation.mutate()}
            >
              {retryMutation.isPending ? "Sending…" : "Retry delivery"}
            </Button>
          )}
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <Badge
            variant={
              data.make_delivery_status === "sent"
                ? "default"
                : data.make_delivery_status === "failed"
                  ? "destructive"
                  : "secondary"
            }
          >
            {data.make_delivery_status}
          </Badge>
          <p className="text-xs text-muted-foreground">
            Attempts: {data.make_attempt_count ?? 0}
            {data.make_delivery_attempted_at
              ? ` · last attempt ${new Date(data.make_delivery_attempted_at).toLocaleString()}`
              : ""}
            {data.make_http_status ? ` · HTTP ${data.make_http_status}` : ""}
          </p>
          {data.make_error_summary && (
            <p className="text-xs text-destructive">{data.make_error_summary}</p>
          )}
          <p className="text-xs text-muted-foreground">
            Retries resend the stored snapshot exactly as calculated; nothing is recalculated and
            the webhook URL and secret stay in server-side configuration.
          </p>
        </CardContent>
      </Card>
        </>
      )}

      {(snapshot?.labs ?? []).map((lab) => (
        <Card key={lab.labName}>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">
              {lab.labName}
              <span className="ml-2 text-sm font-normal text-muted-foreground">
                {money(lab.subtotal, currency)}
              </span>
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            {lab.subLabs.map((section) => (
              <div key={section.subLabName} className="space-y-2">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p className="text-sm font-medium">{section.subLabName}</p>
                  <p className="text-xs text-muted-foreground">
                    Annual volume {section.annualTestVolume.toLocaleString()} · hardware{" "}
                    {money(section.hardwareSubtotal, currency)} · parameters{" "}
                    {money(section.parameterSubtotal, currency)} · subtotal{" "}
                    {money(section.subtotal, currency)}
                  </p>
                </div>
                {section.hardware.length > 0 && (
                  <p className="text-xs text-muted-foreground">
                    Hardware:{" "}
                    {section.hardware
                      .map((h) => `${h.hardwareName} (${money(h.price, currency)})`)
                      .join(", ")}
                  </p>
                )}
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Parameter</TableHead>
                        <TableHead>Price per test</TableHead>
                        <TableHead>Allocated volume</TableHead>
                        <TableHead>Annual cost</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {section.parameters.map((p) => (
                        <TableRow key={p.parameterName}>
                          <TableCell className="text-xs">{p.parameterName}</TableCell>
                          <TableCell className="text-xs">
                            {money(p.pricePerTest, currency)}
                          </TableCell>
                          <TableCell className="text-xs">
                            {Math.round(p.allocatedAnnualVolume).toLocaleString()}
                          </TableCell>
                          <TableCell className="text-xs">
                            {money(p.annualCalculatedCost, currency)}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

const REPORT_LABEL: Record<string, string> = {
  pending: "Pending",
  generating: "Generating",
  generated: "Generated",
  failed: "Failed",
};
const EMAIL_LABEL: Record<string, string> = {
  pending: "Pending",
  sending: "Sending",
  sent: "Sent",
  failed: "Failed",
};

function statusVariant(status: string | null) {
  if (status === "generated" || status === "sent") return "default" as const;
  if (status === "failed") return "destructive" as const;
  return "secondary" as const;
}

function SalesReportCard({
  id,
  data,
  readOnly,
}: {
  id: string;
  data: {
    report_status: string | null;
    report_generated_at: string | null;
    report_error: string | null;
    report_version: number | null;
    email_status: string | null;
    email_sent_at: string | null;
    email_error: string | null;
    email_attempt_count: number | null;
  };
  readOnly: boolean;
}) {
  const queryClient = useQueryClient();
  const getUrl = useServerFn(getSubmissionReportUrl);
  const regenerate = useServerFn(regenerateSubmissionReport);
  const resend = useServerFn(resendSalesNotification);
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ["submission", id] });
    void queryClient.invalidateQueries({ queryKey: ["submissions"] });
  };

  const open = useMutation({
    mutationFn: (download: boolean) => getUrl({ data: { id, download } }),
    onSuccess: (result, download) => {
      if (download) window.location.assign(result.url);
      else window.open(result.url, "_blank", "noopener,noreferrer");
    },
    onError: () => toast.error("The report could not be opened"),
  });
  const regen = useMutation({
    mutationFn: () => regenerate({ data: { id } }),
    onSuccess: (r) => {
      if (r.status === "generated") toast.success("Report regenerated");
      else toast.error("Report generation failed");
      refresh();
    },
    onError: () => toast.error("Regeneration could not be started"),
  });
  const resendMutation = useMutation({
    mutationFn: () => resend({ data: { id } }),
    onSuccess: (r) => {
      if (r.status === "sent") toast.success("Sales notification sent");
      else toast.error(r.error ?? "Notification failed");
      refresh();
    },
    onError: (e) => toast.error(e instanceof Error ? e.message : "Resend could not be started"),
  });

  const generated = data.report_status === "generated";
  const reportLabel = data.report_status ? REPORT_LABEL[data.report_status] : "Not generated";
  const emailLabel = data.email_status ? EMAIL_LABEL[data.email_status] : "Not sent";

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 pb-2">
        <CardTitle className="text-base">Internal Sales Report</CardTitle>
        <div className="flex flex-wrap gap-2">
          {generated && (
            <>
              <Button size="sm" variant="outline" disabled={open.isPending} onClick={() => open.mutate(false)}>
                View PDF
              </Button>
              <Button size="sm" variant="outline" disabled={open.isPending} onClick={() => open.mutate(true)}>
                Download PDF
              </Button>
            </>
          )}
          {!readOnly && (
            <>
              <Button size="sm" variant="outline" disabled={regen.isPending} onClick={() => regen.mutate()}>
                {regen.isPending ? "Generating…" : generated ? "Regenerate PDF" : "Generate PDF"}
              </Button>
              {generated && (
                <Button
                  size="sm"
                  variant="outline"
                  disabled={resendMutation.isPending}
                  onClick={() => resendMutation.mutate()}
                >
                  {resendMutation.isPending ? "Sending…" : "Resend Sales Notification"}
                </Button>
              )}
            </>
          )}
        </div>
      </CardHeader>
      <CardContent className="grid gap-4 text-sm md:grid-cols-2">
        <div className="space-y-1">
          <p className="text-xs text-muted-foreground">Report status</p>
          <Badge variant={statusVariant(data.report_status)}>{reportLabel}</Badge>
          <p className="text-xs text-muted-foreground">
            Generated:{" "}
            {data.report_generated_at ? new Date(data.report_generated_at).toLocaleString() : "—"}
            {data.report_version ? ` · version ${data.report_version}` : ""}
          </p>
          {data.report_error && <p className="text-xs text-destructive">{data.report_error}</p>}
        </div>
        <div className="space-y-1">
          <p className="text-xs text-muted-foreground">Email status</p>
          <Badge variant={statusVariant(data.email_status)}>{emailLabel}</Badge>
          <p className="text-xs text-muted-foreground">
            Sent: {data.email_sent_at ? new Date(data.email_sent_at).toLocaleString() : "—"} · attempts{" "}
            {data.email_attempt_count ?? 0}
          </p>
          {data.email_error && <p className="text-xs text-destructive">{data.email_error}</p>}
        </div>
        <p className="text-xs text-muted-foreground md:col-span-2">
          Built only from the stored submission snapshot — nothing is recalculated or re-priced.
          PDF links are short-lived and require an authorised administrator session.
        </p>
      </CardContent>
    </Card>
  );
}

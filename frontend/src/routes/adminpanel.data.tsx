import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { useAdminAccess } from "@/lib/admin-access";
import { useState } from "react";
import { toast } from "sonner";

import { DatasetTreeView, IssueTable } from "@/components/admin/dataset-preview";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  getVersionDetail,
  listVersions,
  publishVersionFn,
  uploadWorkbook,
} from "@/lib/admin.functions";
import { WORKBOOK_TYPES, type ValidationIssue, type WorkbookType } from "@/lib/workbook-types";

export const Route = createFileRoute("/adminpanel/data")({
  component: DataManagement,
});

function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result);
      resolve(result.slice(result.indexOf(",") + 1));
    };
    reader.onerror = () => reject(new Error("Unable to read the selected file"));
    reader.readAsDataURL(file);
  });
}

function DataManagement() {
  const queryClient = useQueryClient();
  const upload = useServerFn(uploadWorkbook);
  const detail = useServerFn(getVersionDetail);
  const publish = useServerFn(publishVersionFn);
  const { readOnly } = useAdminAccess();
  const fetchVersions = useServerFn(listVersions);

  const [workbookType, setWorkbookType] = useState<WorkbookType>("core_lab");
  const [file, setFile] = useState<File | null>(null);
  const [notes, setNotes] = useState("");
  const [draftId, setDraftId] = useState<string | null>(null);

  const versionsQuery = useQuery({ queryKey: ["versions"], queryFn: () => fetchVersions() });

  const detailQuery = useQuery({
    queryKey: ["version-detail", draftId],
    queryFn: () => detail({ data: { id: draftId! } }),
    enabled: Boolean(draftId),
  });

  const uploadMutation = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error("Select a workbook file first");
      const fileBase64 = await fileToBase64(file);
      return upload({
        data: { workbookType, filename: file.name, fileBase64, notes: notes || null },
      });
    },
    onSuccess: (result) => {
      setDraftId(result.versionId);
      queryClient.invalidateQueries({ queryKey: ["versions"] });
      const critical = result.summary?.criticalErrors ?? result.issues.length;
      if (critical > 0) toast.error(`Parsed with ${critical} critical error(s)`);
      else toast.success("Workbook parsed and validated — review before publishing");
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const publishMutation = useMutation({
    mutationFn: async () => publish({ data: { id: draftId! } }),
    onSuccess: () => {
      toast.success("Dataset published");
      queryClient.invalidateQueries({ queryKey: ["versions"] });
      queryClient.invalidateQueries({ queryKey: ["admin-dashboard"] });
      queryClient.invalidateQueries({ queryKey: ["version-detail", draftId] });
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const version = detailQuery.data?.tree.version ?? null;
  const summary = (version?.validation_summary ?? {}) as Record<string, number>;
  const issues = (version?.validation_issues ?? []) as unknown as ValidationIssue[];
  const diff = detailQuery.data?.diff ?? null;
  const criticalErrors = summary["criticalErrors"] ?? 0;

  const drafts = (versionsQuery.data ?? []).filter(
    (v) => v.status === "validated" || v.status === "validation_failed" || v.status === "uploaded",
  );

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold">Data Management</h1>
        <p className="text-sm text-muted-foreground">
          Upload → Parse → Validate → Preview → Publish. Uploading never makes data live.
        </p>
      </div>

      {!readOnly && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Upload workbook</CardTitle>
            <CardDescription>
              Select the intended workbook type; the structure is validated against it.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label>Workbook type</Label>
              <Select
                value={workbookType}
                onValueChange={(v) => setWorkbookType(v as WorkbookType)}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {WORKBOOK_TYPES.map((t) => (
                    <SelectItem key={t.value} value={t.value}>
                      {t.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="workbook">Excel workbook (.xlsx)</Label>
              <Input
                id="workbook"
                type="file"
                accept=".xlsx,.xls"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              />
            </div>
            <div className="space-y-2 md:col-span-2">
              <Label htmlFor="notes">Notes (optional)</Label>
              <Textarea id="notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
            <div className="md:col-span-2">
              <Button
                onClick={() => uploadMutation.mutate()}
                disabled={!file || uploadMutation.isPending}
              >
                {uploadMutation.isPending ? "Parsing…" : "Upload, parse & validate"}
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {!draftId && drafts.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Recent draft versions</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {drafts.slice(0, 8).map((v) => (
              <button
                key={v.id}
                onClick={() => setDraftId(v.id)}
                className="flex w-full items-center justify-between rounded-md border px-3 py-2 text-left text-sm hover:bg-accent"
              >
                <span>
                  {v.workbook_type} v{v.version_number} — {v.original_filename}
                </span>
                <Badge variant={v.status === "validated" ? "default" : "destructive"}>
                  {v.status}
                </Badge>
              </button>
            ))}
          </CardContent>
        </Card>
      )}

      {version && (
        <>
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Validation results</CardTitle>
              <CardDescription>
                {version.original_filename} · {version.workbook_type} · draft v
                {version.version_number} · uploaded {new Date(version.uploaded_at).toLocaleString()}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-4 lg:grid-cols-8">
                {[
                  ["Laboratories", summary["laboratories"]],
                  ["Sub-labs", summary["subLabs"]],
                  ["Parameter groups", summary["parameterGroups"]],
                  ["Parameters", summary["parameters"]],
                  ["Hardware", summary["hardware"]],
                  ["HW categories", summary["hardwareCategories"]],
                  ["Critical errors", summary["criticalErrors"]],
                  ["Warnings", summary["warnings"]],
                ].map(([label, value]) => (
                  <div key={String(label)} className="rounded-md border p-3">
                    <p className="text-xs text-muted-foreground">{label}</p>
                    <p className="text-lg font-semibold">{value ?? 0}</p>
                  </div>
                ))}
              </div>

              {criticalErrors > 0 ? (
                <Alert variant="destructive">
                  <AlertTitle>Publishing blocked</AlertTitle>
                  <AlertDescription>
                    Fix the {criticalErrors} critical error(s) in the source workbook and upload
                    again.
                  </AlertDescription>
                </Alert>
              ) : (
                <Alert>
                  <AlertTitle>DRAFT — NOT LIVE</AlertTitle>
                  <AlertDescription>
                    Validated successfully. Review the preview below, then publish explicitly.
                  </AlertDescription>
                </Alert>
              )}

              <IssueTable issues={issues} />

              {!readOnly && (
                <Button
                  onClick={() => publishMutation.mutate()}
                  disabled={
                    criticalErrors > 0 ||
                    version.status === "published" ||
                    publishMutation.isPending
                  }
                >
                  {version.status === "published" ? "Published" : "Publish this version"}
                </Button>
              )}
            </CardContent>
          </Card>

          {!diff && !detailQuery.isLoading && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Comparison</CardTitle>
              </CardHeader>
              <CardContent className="text-sm text-muted-foreground">
                Initial dataset — no previous published version available for comparison.
              </CardContent>
            </Card>
          )}

          {diff && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  Changes vs published v{diff.comparedWithVersion}
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                <DiffList title="Parameters added" items={diff.parametersAdded} />
                <DiffList title="Parameters removed" items={diff.parametersRemoved} />
                <DiffList
                  title="Parameter price changes"
                  items={diff.parameterPriceChanges.map(
                    (c) => `${c.name}: ${c.from ?? "—"} → ${c.to ?? "—"}`,
                  )}
                />
                <DiffList title="Hardware added" items={diff.hardwareAdded} />
                <DiffList title="Hardware removed" items={diff.hardwareRemoved} />
                <DiffList
                  title="Hardware price changes"
                  items={diff.hardwarePriceChanges.map(
                    (c) => `${c.name}: ${c.from ?? "—"} → ${c.to ?? "—"}`,
                  )}
                />
                <DiffList title="Parameter Group relationships" items={diff.groupChanges} />
                <DiffList title="Histopathology categories" items={diff.categoryChanges} />
              </CardContent>
            </Card>
          )}

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Preview — DRAFT, NOT LIVE</CardTitle>
              <CardDescription>Admin-only view including internal pricing.</CardDescription>
            </CardHeader>
            <CardContent>
              <DatasetTreeView tree={detailQuery.data!.tree} />
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

function DiffList({ title, items }: { title: string; items: string[] }) {
  return (
    <div className="rounded-md border p-3">
      <p className="mb-1 font-medium">
        {title} ({items.length})
      </p>
      {items.length === 0 ? (
        <p className="text-muted-foreground">No changes</p>
      ) : (
        <ul className="max-h-48 list-disc space-y-0.5 overflow-auto pl-4 text-muted-foreground">
          {items.slice(0, 200).map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
      )}
    </div>
  );
}

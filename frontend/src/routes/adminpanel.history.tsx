import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { useAdminAccess } from "@/lib/admin-access";
import { useState } from "react";
import { toast } from "sonner";

import { DatasetTreeView, IssueTable } from "@/components/admin/dataset-preview";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
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
import { getVersionDetail, listVersions, publishVersionFn } from "@/lib/admin.functions";
import type { ValidationIssue } from "@/lib/workbook-types";

export const Route = createFileRoute("/adminpanel/history")({
  component: DatasetHistory,
});

function DatasetHistory() {
  const queryClient = useQueryClient();
  const fetchVersions = useServerFn(listVersions);
  const detail = useServerFn(getVersionDetail);
  const publish = useServerFn(publishVersionFn);
  const { readOnly } = useAdminAccess();
  const [selected, setSelected] = useState<string | null>(null);

  const versionsQuery = useQuery({ queryKey: ["versions"], queryFn: () => fetchVersions() });
  const detailQuery = useQuery({
    queryKey: ["version-detail", selected],
    queryFn: () => detail({ data: { id: selected! } }),
    enabled: Boolean(selected),
  });

  const rollback = useMutation({
    mutationFn: (id: string) => publish({ data: { id, rollback: true } }),
    onSuccess: () => {
      toast.success("Version restored and published");
      queryClient.invalidateQueries({ queryKey: ["versions"] });
      queryClient.invalidateQueries({ queryKey: ["admin-dashboard"] });
      queryClient.invalidateQueries({ queryKey: ["published-data"] });
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const version = detailQuery.data?.tree.version ?? null;
  const issues = (version?.validation_issues ?? []) as unknown as ValidationIssue[];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Dataset History</h1>
        <p className="text-sm text-muted-foreground">
          Every import is retained. Restoring a version never destroys history.
        </p>
      </div>

      {versionsQuery.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto pt-6">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Version</TableHead>
                  <TableHead>Dataset</TableHead>
                  <TableHead>Filename</TableHead>
                  <TableHead>Uploaded</TableHead>
                  <TableHead>Validation</TableHead>
                  <TableHead>Published</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(versionsQuery.data ?? []).map((v) => {
                  const summary = (v.validation_summary ?? {}) as Record<string, number>;
                  return (
                    <TableRow key={v.id}>
                      <TableCell>v{v.version_number}</TableCell>
                      <TableCell>{v.workbook_type}</TableCell>
                      <TableCell className="max-w-48 truncate">{v.original_filename}</TableCell>
                      <TableCell className="text-xs">
                        {new Date(v.uploaded_at).toLocaleString()}
                      </TableCell>
                      <TableCell className="text-xs">
                        {summary["criticalErrors"] ?? 0} errors / {summary["warnings"] ?? 0}{" "}
                        warnings
                      </TableCell>
                      <TableCell className="text-xs">
                        {v.published_at ? new Date(v.published_at).toLocaleString() : "—"}
                      </TableCell>
                      <TableCell>
                        <Badge
                          variant={
                            v.status === "published"
                              ? "default"
                              : v.status === "validation_failed"
                                ? "destructive"
                                : "secondary"
                          }
                        >
                          {v.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="space-x-2 text-right">
                        <Button size="sm" variant="outline" onClick={() => setSelected(v.id)}>
                          Inspect
                        </Button>
                        {!readOnly &&
                          v.status === "archived" &&
                          (summary["criticalErrors"] ?? 0) === 0 && (
                            <AlertDialog>
                              <AlertDialogTrigger asChild>
                                <Button size="sm" variant="secondary">
                                  Restore
                                </Button>
                              </AlertDialogTrigger>
                              <AlertDialogContent>
                                <AlertDialogHeader>
                                  <AlertDialogTitle>Restore this version?</AlertDialogTitle>
                                  <AlertDialogDescription>
                                    {v.workbook_type} v{v.version_number} becomes the live dataset.
                                    The current published version is archived and remains in
                                    history.
                                  </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                                  <AlertDialogAction onClick={() => rollback.mutate(v.id)}>
                                    Restore this version
                                  </AlertDialogAction>
                                </AlertDialogFooter>
                              </AlertDialogContent>
                            </AlertDialog>
                          )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {version && detailQuery.data && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {version.workbook_type} v{version.version_number} — {version.original_filename}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            <IssueTable issues={issues} />
            <DatasetTreeView tree={detailQuery.data.tree} />
          </CardContent>
        </Card>
      )}
    </div>
  );
}

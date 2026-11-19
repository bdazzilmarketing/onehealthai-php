import { useQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useState } from "react";

import { DatasetTreeView } from "@/components/admin/dataset-preview";
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
import { Skeleton } from "@/components/ui/skeleton";
import { getPublishedData } from "@/lib/admin.functions";
import { WORKBOOK_TYPES, type WorkbookType } from "@/lib/workbook-types";

export const Route = createFileRoute("/adminpanel/published")({
  component: PublishedData,
});

function PublishedData() {
  const fetchPublished = useServerFn(getPublishedData);
  const [workbookType, setWorkbookType] = useState<WorkbookType>("core_lab");
  const [search, setSearch] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["published-data", workbookType],
    queryFn: () => fetchPublished({ data: { workbookType } }),
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Published Data</h1>
        <p className="text-sm text-muted-foreground">
          Read-only view of live data, including internal pricing. Updates come from Excel imports
          only.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Filters</CardTitle>
          <CardDescription>Search across parameters and hardware.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          <div className="space-y-2">
            <Label>Laboratory</Label>
            <Select value={workbookType} onValueChange={(v) => setWorkbookType(v as WorkbookType)}>
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
            <Label htmlFor="search">Search</Label>
            <Input
              id="search"
              value={search}
              placeholder="Parameter, test or hardware name"
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </CardContent>
      </Card>

      {isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : data?.tree ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              Version {data.tree.version?.version_number} — {data.tree.version?.original_filename}
            </CardTitle>
            <CardDescription>
              Published {new Date(data.tree.version?.published_at ?? "").toLocaleString()}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <DatasetTreeView tree={data.tree} search={search} />
          </CardContent>
        </Card>
      ) : (
        <p className="text-sm text-muted-foreground">
          No published dataset for this laboratory yet.
        </p>
      )}
    </div>
  );
}

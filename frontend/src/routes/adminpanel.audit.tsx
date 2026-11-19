import { useQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { getAuditLog } from "@/lib/admin.functions";

export const Route = createFileRoute("/adminpanel/audit")({
  component: AuditLog,
});

function AuditLog() {
  const fetchAudit = useServerFn(getAuditLog);
  const { data, isLoading } = useQuery({ queryKey: ["audit"], queryFn: () => fetchAudit() });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Audit Log</h1>
        <p className="text-sm text-muted-foreground">
          Append-only administrative record. Admins can read and add events but cannot edit or
          delete them; the structure is ready for later SIEM/SOC forwarding.
        </p>
      </div>

      {isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto pt-6">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Timestamp</TableHead>
                  <TableHead>Event</TableHead>
                  <TableHead>Actor</TableHead>
                  <TableHead>Details</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(data ?? []).map((event) => (
                  <TableRow key={event.id}>
                    <TableCell className="whitespace-nowrap text-xs">
                      {new Date(event.created_at).toLocaleString()}
                    </TableCell>
                    <TableCell className="font-mono text-xs">{event.event_type}</TableCell>
                    <TableCell className="text-xs">{event.actor_email ?? "—"}</TableCell>
                    <TableCell className="max-w-xl text-xs text-muted-foreground">
                      <code className="break-all">{JSON.stringify(event.details)}</code>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

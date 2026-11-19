import { useQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useMemo, useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { listCalculatorSubmissions } from "@/lib/admin.functions";

export const Route = createFileRoute("/adminpanel/submissions/")({
  component: Submissions,
});

const STATUS_VARIANT: Record<string, "default" | "secondary" | "destructive"> = {
  sent: "default",
  pending: "secondary",
  failed: "destructive",
};

function money(amount: number, currency: string) {
  return `${currency} ${Number(amount).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

/** New submissions use the native sales email; older ones keep their Make.com status. */
function deliveryStatus(row: { make_delivery_status: string; email_status: string | null }) {
  if (row.make_delivery_status === "disabled") return row.email_status ?? "not sent";
  return row.make_delivery_status;
}

function Submissions() {
  const fetchSubmissions = useServerFn(listCalculatorSubmissions);
  const { data, isLoading } = useQuery({
    queryKey: ["submissions"],
    queryFn: () => fetchSubmissions(),
  });

  const [search, setSearch] = useState("");
  const [mode, setMode] = useState("all");
  const [status, setStatus] = useState("all");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");

  const rows = useMemo(() => {
    const term = search.trim().toLowerCase();
    return (data ?? []).filter((row) => {
      if (mode !== "all" && row.selected_mode !== mode) return false;
      if (status !== "all" && deliveryStatus(row) !== status) return false;
      if (from && new Date(row.created_at) < new Date(from)) return false;
      if (to && new Date(row.created_at) > new Date(`${to}T23:59:59`)) return false;
      if (!term) return true;
      return [row.reference_id, row.full_name, row.company, row.email, row.phone]
        .join(" ")
        .toLowerCase()
        .includes(term);
    });
  }, [data, search, mode, status, from, to]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Submissions</h1>
        <p className="text-sm text-muted-foreground">
          Completed calculator runs with their immutable internal calculation snapshot and sales notification
          delivery status. Admin-only; never exposed to the public site.
        </p>
      </div>

      <Card>
        <CardContent className="grid gap-3 pt-6 md:grid-cols-5">
          <Input
            placeholder="Search reference, name, company, email"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="md:col-span-2"
          />
          <Select value={mode} onValueChange={setMode}>
            <SelectTrigger>
              <SelectValue placeholder="Mode" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All modes</SelectItem>
              <SelectItem value="manual">Manual</SelectItem>
              <SelectItem value="ai">AI</SelectItem>
            </SelectContent>
          </Select>
          <Select value={status} onValueChange={setStatus}>
            <SelectTrigger>
              <SelectValue placeholder="Delivery status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="sent">Sent</SelectItem>
              <SelectItem value="failed">Failed</SelectItem>
            </SelectContent>
          </Select>
          <div className="flex gap-2">
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
        </CardContent>
      </Card>

      {isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <Card>
          <CardContent className="overflow-x-auto pt-6">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Reference</TableHead>
                  <TableHead>Date / time</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Company</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Phone</TableHead>
                  <TableHead>Mode</TableHead>
                  <TableHead>Public estimate</TableHead>
                  <TableHead>Sales email</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="font-mono text-xs">{row.reference_id}</TableCell>
                    <TableCell className="whitespace-nowrap text-xs">
                      {new Date(row.created_at).toLocaleString()}
                    </TableCell>
                    <TableCell className="text-xs">{row.full_name}</TableCell>
                    <TableCell className="text-xs">{row.company}</TableCell>
                    <TableCell className="text-xs">{row.email}</TableCell>
                    <TableCell className="text-xs">{row.phone}</TableCell>
                    <TableCell className="text-xs uppercase">{row.selected_mode}</TableCell>
                    <TableCell className="whitespace-nowrap text-xs">
                      {money(row.public_low, row.currency)} – {money(row.public_high, row.currency)}
                    </TableCell>
                    <TableCell>
                      <Badge variant={STATUS_VARIANT[deliveryStatus(row)] ?? "secondary"}>
                        {deliveryStatus(row)}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <Link
                        to="/adminpanel/submissions/$id"
                        params={{ id: row.id }}
                        className="text-xs font-medium underline"
                      >
                        View
                      </Link>
                    </TableCell>
                  </TableRow>
                ))}
                {rows.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={10} className="text-center text-xs text-muted-foreground">
                      No submissions match the current filters.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

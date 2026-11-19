import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useAdminAccess } from "@/lib/admin-access";
import { addSalesRecipient, listSalesRecipients, removeSalesRecipient } from "@/lib/admin.functions";

export const Route = createFileRoute("/adminpanel/notifications")({
  component: SalesReportNotifications,
});

const EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

function SalesReportNotifications() {
  const queryClient = useQueryClient();
  const { readOnly } = useAdminAccess();
  const fetchRecipients = useServerFn(listSalesRecipients);
  const add = useServerFn(addSalesRecipient);
  const remove = useServerFn(removeSalesRecipient);
  const [email, setEmail] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["sales-recipients"],
    queryFn: () => fetchRecipients(),
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["sales-recipients"] });

  const addMutation = useMutation({
    mutationFn: (value: string) => add({ data: { email: value } }),
    onSuccess: () => {
      toast.success("Recipient added");
      setEmail("");
      void refresh();
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : "Unable to add recipient"),
  });

  const removeMutation = useMutation({
    mutationFn: (id: string) => remove({ data: { id } }),
    onSuccess: () => {
      toast.success("Recipient removed");
      void refresh();
    },
    onError: () => toast.error("Unable to remove recipient"),
  });

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    const value = email.trim().toLowerCase();
    if (!EMAIL_RE.test(value)) {
      toast.error("Enter a valid email address");
      return;
    }
    if (data?.some((r) => r.email === value)) {
      toast.error("This email is already a recipient");
      return;
    }
    addMutation.mutate(value);
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Sales Report Notifications</h1>
        <p className="text-sm text-muted-foreground">
          Every active recipient is emailed when a new calculator submission is completed. The email
          links to the internal report in this portal; sign-in is required to open it.
        </p>
      </div>

      {!readOnly && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Add recipient</CardTitle>
          </CardHeader>
          <CardContent>
            <form className="flex flex-col gap-3 sm:flex-row sm:items-end" onSubmit={submit}>
              <div className="flex-1 space-y-2">
                <Label htmlFor="recipient-email">Email address</Label>
                <Input
                  id="recipient-email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="name@company.com"
                />
              </div>
              <Button type="submit" disabled={addMutation.isPending}>
                {addMutation.isPending ? "Adding…" : "Add recipient"}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base">Recipients</CardTitle>
          <CardDescription>
            Changes apply to new submissions only; past submissions keep their delivery record.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-24 w-full" />
          ) : !data || data.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No recipients configured. New submissions will be stored but no email will be sent.
            </p>
          ) : (
            <ul className="divide-y">
              {data.map((recipient) => (
                <li key={recipient.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="flex items-center gap-3">
                    <span className="text-sm">{recipient.email}</span>
                    <Badge variant={recipient.active ? "default" : "secondary"}>
                      {recipient.active ? "Active" : "Inactive"}
                    </Badge>
                  </div>
                  {!readOnly && (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={removeMutation.isPending}
                      onClick={() => {
                        if (window.confirm(`Remove ${recipient.email}?`)) removeMutation.mutate(recipient.id);
                      }}
                    >
                      Remove
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { useAdminAccess } from "@/lib/admin-access";
import { useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  createUser,
  listUsers,
  sendUserAccountEmail,
  setUserEnabled,
} from "@/lib/admin.functions";

export const Route = createFileRoute("/adminpanel/users")({
  component: UserManagement,
});

function UserManagement() {
  const queryClient = useQueryClient();
  const fetchUsers = useServerFn(listUsers);
  const invite = useServerFn(createUser);
  const setEnabled = useServerFn(setUserEnabled);
  const sendAccountEmail = useServerFn(sendUserAccountEmail);
  const { readOnly, superAdmin } = useAdminAccess();
  const [pending, setPending] = useState<{
    userId: string;
    email: string;
    kind: "invite" | "reset";
  } | null>(null);

  const [email, setEmail] = useState("");
  const [role, setRole] = useState<"admin" | "security_auditor">("admin");

  const usersQuery = useQuery({ queryKey: ["admin-users"], queryFn: () => fetchUsers() });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ["admin-users"] });

  const inviteMutation = useMutation({
    mutationFn: () => invite({ data: { email, sendInvite: true, role } }),
    onSuccess: () => {
      toast.success(
        role === "security_auditor" ? "Security Auditor invited" : "Administrator invited",
      );
      setEmail("");
      invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const toggleMutation = useMutation({
    mutationFn: (input: { userId: string; enabled: boolean }) => setEnabled({ data: input }),
    onSuccess: () => {
      toast.success("Account updated");
      invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const emailMutation = useMutation({
    mutationFn: (input: { userId: string; kind: "invite" | "reset" }) =>
      sendAccountEmail({ data: input }),
    onSuccess: (result, input) => {
      toast.success(
        input.kind === "invite"
          ? `A new invitation was sent to ${result.email}`
          : `A password reset email was sent to ${result.email}`,
      );
      invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
    onSettled: () => setPending(null),
  });

  if (usersQuery.isError) {
    return (
      <div className="space-y-2">
        <h1 className="text-2xl font-semibold">User Management</h1>
        <p className="text-sm text-destructive">
          This section is restricted to Super Admin accounts.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">User Management</h1>
        <p className="text-sm text-muted-foreground">
          Super Admin only. Credentials are managed by the authentication provider — passwords are
          never stored or readable here. Every action is recorded in the Audit Log.
        </p>
      </div>

      {!readOnly && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Invite an administrator</CardTitle>
            <CardDescription>
              The invited user receives the selected role and sets their own password through the
              invitation email. Security Auditors have read-only access.
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap items-end gap-3">
            <div className="min-w-64 flex-1 space-y-2">
              <Label htmlFor="invite-email">Work email</Label>
              <Input
                id="invite-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="invite-role">Role</Label>
              <select
                id="invite-role"
                className="h-9 rounded-md border bg-background px-3 text-sm"
                value={role}
                onChange={(e) => setRole(e.target.value as "admin" | "security_auditor")}
              >
                <option value="admin">Admin</option>
                <option value="security_auditor">Security Auditor (Read Only)</option>
              </select>
            </div>
            <Button
              disabled={!email || inviteMutation.isPending}
              onClick={() => inviteMutation.mutate()}
            >
              Send invitation
            </Button>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Administrative users</CardTitle>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          {usersQuery.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Email</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Created</TableHead>
                  <TableHead>Last login</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(usersQuery.data ?? []).map((user) => (
                  <TableRow key={user.id}>
                    <TableCell className="text-sm">{user.email ?? "—"}</TableCell>
                    <TableCell>
                      <Badge variant={user.role === "super_admin" ? "default" : "secondary"}>
                        {user.role === "super_admin"
                          ? "Super Admin"
                          : user.role === "admin"
                            ? "Admin"
                            : user.role === "security_auditor"
                              ? "Security Auditor (Read Only)"
                              : "No role"}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <Badge variant={user.status === "active" ? "outline" : "destructive"}>
                        {user.status}
                      </Badge>
                      {!user.activated && user.status === "active" && (
                        <Badge variant="secondary" className="ml-1">
                          invitation pending
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-xs">
                      {new Date(user.created_at).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-xs">
                      {user.last_login_at ? new Date(user.last_login_at).toLocaleString() : "—"}
                    </TableCell>
                    <TableCell className="space-x-2 whitespace-nowrap text-right">
                      {!readOnly && superAdmin && user.status === "active" && user.email && user.role !== "none" && (
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={emailMutation.isPending}
                          onClick={() =>
                            setPending({
                              userId: user.id,
                              email: user.email!,
                              kind: user.activated ? "reset" : "invite",
                            })
                          }
                        >
                          {user.activated ? "Send Password Reset" : "Resend Invitation"}
                        </Button>
                      )}
                      {!readOnly && (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={toggleMutation.isPending}
                          onClick={() =>
                            toggleMutation.mutate({
                              userId: user.id,
                              enabled: user.status !== "active",
                            })
                          }
                        >
                          {user.status === "active" ? "Disable" : "Re-enable"}
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <AlertDialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {pending?.kind === "invite" ? "Resend invitation" : "Send password reset"}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {pending?.kind === "invite"
                ? `Send a new invitation to ${pending?.email}?`
                : `Send a password reset email to ${pending?.email}?`}{" "}
              The link works once and expires after a limited time. The account's role is not
              changed.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={emailMutation.isPending}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              disabled={emailMutation.isPending}
              onClick={(event) => {
                event.preventDefault();
                if (pending) emailMutation.mutate({ userId: pending.userId, kind: pending.kind });
              }}
            >
              Send
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

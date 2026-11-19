import { useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute, Link, Outlet } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { MfaSetup } from "@/components/admin/MfaSetup";
import { getSession, recordSignIn } from "@/lib/admin.functions";
import { authClient } from "@/lib/auth/client";

type NavTo =
  | "/adminpanel"
  | "/adminpanel/data"
  | "/adminpanel/published"
  | "/adminpanel/history"
  | "/adminpanel/submissions"
  | "/adminpanel/ai"
  | "/adminpanel/settings"
  | "/adminpanel/integrations"
  | "/adminpanel/notifications"
  | "/adminpanel/audit"
  | "/adminpanel/users";

const NAV: { to: NavTo; label: string; exact?: boolean; superAdminOnly?: boolean }[] = [
  { to: "/adminpanel", label: "Dashboard", exact: true },
  { to: "/adminpanel/data", label: "Data Management" },
  { to: "/adminpanel/published", label: "Published Data" },
  { to: "/adminpanel/history", label: "Dataset History" },
  { to: "/adminpanel/submissions", label: "Submissions" },
  { to: "/adminpanel/ai", label: "AI Configuration" },
  { to: "/adminpanel/settings", label: "Calculator Settings" },
  { to: "/adminpanel/notifications", label: "Sales Report Notifications" },
  { to: "/adminpanel/integrations", label: "Integrations" },
  { to: "/adminpanel/audit", label: "Audit Log" },
  { to: "/adminpanel/users", label: "User Management", superAdminOnly: true },
];

export const Route = createFileRoute("/adminpanel")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Administrator Login — One Health AI Lab Calculator" },
      {
        name: "description",
        content: "Administrator access to the One Health AI Lab Calculator management portal.",
      },
      { name: "robots", content: "noindex, nofollow" },
    ],
  }),
  component: AdminPanel,
});

function AdminPanel() {
  const queryClient = useQueryClient();
  const session = authClient.useSession();
  const signedIn = session.isPending ? null : Boolean(session.data);

  if (signedIn === null) {
    return (
      <div className="space-y-4 p-8">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!signedIn) return <AdminLogin />;

  return <AdminShell onSignOut={() => queryClient.clear()} />;
}

function AdminLogin() {
  const markSignIn = useServerFn(recordSignIn);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [step, setStep] = useState<"password" | "totp">("password");
  const [useBackupCode, setUseBackupCode] = useState(false);
  const [busy, setBusy] = useState(false);

  const finish = async () => {
    try {
      await markSignIn();
    } catch {
      /* login auditing must never block access */
    }
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    const { data, error } = await authClient.signIn.email({ email, password });
    if (error) {
      setBusy(false);
      toast.error(
        error.status === 429
          ? "Too many attempts. Wait a minute and try again."
          : error.status === 403
            ? "This account has been disabled. Contact a Super Admin."
            : "Sign in failed. Check your email and password.",
      );
      return;
    }
    if (data && "twoFactorRedirect" in data && data.twoFactorRedirect) {
      setBusy(false);
      setStep("totp");
      return;
    }
    await finish();
    setBusy(false);
  };

  const submitCode = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    const { error } = useBackupCode
      ? await authClient.twoFactor.verifyBackupCode({ code: code.trim() })
      : await authClient.twoFactor.verifyTotp({ code: code.replace(/\s/g, "") });
    if (error) {
      setBusy(false);
      toast.error(
        error.status === 429
          ? "Too many attempts. Wait a minute and try again."
          : "That code was not accepted. Check your authenticator app and try again.",
      );
      return;
    }
    await finish();
    setBusy(false);
  };

  if (step === "totp") {
    return (
      <main className="flex min-h-screen items-center justify-center bg-muted/30 px-4">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-xl">One Health AI Lab Calculator</CardTitle>
            <p className="text-sm text-muted-foreground">Two-step verification</p>
          </CardHeader>
          <CardContent>
            <form className="space-y-4" onSubmit={submitCode}>
              <div className="space-y-2">
                <Label htmlFor="code">
                  {useBackupCode ? "Backup code" : "6-digit code from your authenticator app"}
                </Label>
                <Input
                  id="code"
                  inputMode={useBackupCode ? "text" : "numeric"}
                  autoComplete="one-time-code"
                  required
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                />
              </div>
              <Button type="submit" className="w-full" disabled={busy}>
                Verify
              </Button>
              <Button
                type="button"
                variant="link"
                className="w-full"
                onClick={() => {
                  setUseBackupCode(!useBackupCode);
                  setCode("");
                }}
              >
                {useBackupCode ? "Use the authenticator app instead" : "Use a backup code instead"}
              </Button>
            </form>
          </CardContent>
        </Card>
      </main>
    );
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-muted/30 px-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-xl">One Health AI Lab Calculator</CardTitle>
          <p className="text-sm text-muted-foreground">Administrator Login</p>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={submit}>
            <div className="space-y-2">
              <Label htmlFor="email">Work Email</Label>
              <Input
                id="email"
                type="email"
                autoComplete="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="password">Password</Label>
              <Input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>
            <Button type="submit" className="w-full" disabled={busy}>
              Sign In
            </Button>
          </form>
        </CardContent>
      </Card>
    </main>
  );
}

function AdminShell({ onSignOut }: { onSignOut: () => void }) {
  const fetchSession = useServerFn(getSession);
  const { data, isLoading } = useQuery({
    queryKey: ["admin-session"],
    queryFn: () => fetchSession(),
  });

  const signOut = async () => {
    onSignOut();
    await authClient.signOut();
  };

  if (isLoading) {
    return (
      <div className="space-y-4 p-8">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (data?.admin && data.mfa.required && !data.mfa.enrolled) {
    return <MfaSetup email={data.email} onSignOut={signOut} />;
  }

  if (!data?.admin) {
    return (
      <main className="flex min-h-screen flex-col items-center justify-center gap-4 px-4 text-center">
        <h1 className="text-xl font-semibold">Administrator access required</h1>
        <p className="max-w-md text-sm text-muted-foreground">
          This account does not have access to the administrator portal.
        </p>
        <Button variant="outline" onClick={signOut}>
          Sign out
        </Button>
      </main>
    );
  }

  const nav = NAV.filter((item) => !item.superAdminOnly || data.superAdmin || data.readOnly);

  return (
    <div className="min-h-screen bg-muted/20">
      <header className="border-b bg-background">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-6 py-4">
          <div>
            <p className="text-sm font-semibold">One Health AI Lab Calculator</p>
            <p className="text-xs text-muted-foreground">Admin Portal</p>
          </div>
          <div className="flex items-center gap-3">
            <span className="text-xs text-muted-foreground">
              {data.email}
              {data.superAdmin ? " · Super Admin" : ""}
            </span>
            {data.readOnly && (
              <span className="rounded-md border px-2 py-0.5 text-xs font-medium">
                Security Auditor · Read Only
              </span>
            )}
            <Button size="sm" variant="outline" onClick={signOut}>
              Sign out
            </Button>
          </div>
        </div>
        <nav className="mx-auto flex max-w-7xl flex-wrap gap-1 px-4 pb-2">
          {nav.map((item) => (
            <Link
              key={item.to}
              to={item.to}
              activeOptions={{ exact: item.exact ?? false }}
              className="rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-accent"
              activeProps={{ className: "bg-accent font-medium text-foreground" }}
            >
              {item.label}
            </Link>
          ))}
        </nav>
      </header>
      <main className="mx-auto max-w-7xl px-6 py-8">
        <Outlet />
      </main>
    </div>
  );
}

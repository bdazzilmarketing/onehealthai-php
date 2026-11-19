import { Link, useNavigate } from "@tanstack/react-router";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { authClient } from "@/lib/auth/client";

type Mode = "activate" | "reset";

type State =
  | { kind: "checking" }
  | { kind: "error"; title: string; message: string }
  | { kind: "ready"; token: string };

const MIN_PASSWORD = 12;

function describeLinkError(mode: Mode, expired: boolean): { title: string; message: string } {
  const noun = mode === "reset" ? "password reset" : "invitation";
  if (expired) {
    return {
      title: `This ${noun} link has expired or was already used`,
      message: `Links work once and expire after a limited time. Ask a Super Admin to send you a new ${noun}.`,
    };
  }
  return {
    title: `This ${noun} link is not valid`,
    message: `Ask a Super Admin to send you a new ${noun}.`,
  };
}

export function PasswordLinkPage({ mode }: { mode: Mode }) {
  const navigate = useNavigate();
  const [state, setState] = useState<State>({ kind: "checking" });
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [busy, setBusy] = useState(false);
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    // Read the single-use token, then remove it from the address bar and history.
    const token = new URLSearchParams(window.location.search).get("token");
    window.history.replaceState(null, "", window.location.pathname);
    if (!token || !/^[A-Za-z0-9_-]{20,200}$/.test(token)) {
      setState({ kind: "error", ...describeLinkError(mode, false) });
      return;
    }
    setState({ kind: "ready", token });
  }, [mode]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (state.kind !== "ready") return;
    if (password.length < MIN_PASSWORD) {
      toast.error(`Use at least ${MIN_PASSWORD} characters.`);
      return;
    }
    if (password !== confirm) {
      toast.error("The passwords do not match.");
      return;
    }
    setBusy(true);
    const { error } = await authClient.resetPassword({ newPassword: password, token: state.token });
    setBusy(false);
    if (error) {
      if (error.code === "INVALID_TOKEN") {
        setState({ kind: "error", ...describeLinkError(mode, true) });
        return;
      }
      toast.error(
        error.status === 429
          ? "Too many attempts. Wait a minute and try again."
          : `Could not set the password. Please try again or request a new ${mode === "reset" ? "password reset" : "invitation"}.`,
      );
      return;
    }
    toast.success(
      mode === "reset"
        ? "Password updated. Sign in with your new password."
        : "Account activated. Sign in to continue.",
    );
    navigate({ to: "/adminpanel", replace: true });
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-muted/30 px-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle className="text-xl">One Health AI Lab Calculator</CardTitle>
          <p className="text-sm text-muted-foreground">
            {mode === "reset" ? "Reset administrator password" : "Activate administrator account"}
          </p>
        </CardHeader>
        <CardContent>
          {state.kind === "checking" && (
            <div className="space-y-3">
              <Skeleton className="h-6 w-2/3" />
              <Skeleton className="h-24 w-full" />
            </div>
          )}

          {state.kind === "error" && (
            <div className="space-y-4">
              <h1 className="text-base font-semibold">{state.title}</h1>
              <p className="text-sm text-muted-foreground">{state.message}</p>
              <Button asChild variant="outline" className="w-full">
                <Link to="/adminpanel">Go to Administrator Login</Link>
              </Button>
            </div>
          )}

          {state.kind === "ready" && (
            <form className="space-y-4" onSubmit={submit}>
              <div className="space-y-2">
                <Label htmlFor="new-password">New password</Label>
                <Input
                  id="new-password"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={MIN_PASSWORD}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
                <p className="text-xs text-muted-foreground">At least {MIN_PASSWORD} characters.</p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="confirm-password">Confirm password</Label>
                <Input
                  id="confirm-password"
                  type="password"
                  autoComplete="new-password"
                  required
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                />
              </div>
              <Button type="submit" className="w-full" disabled={busy}>
                {mode === "reset" ? "Save new password and continue" : "Set password and continue"}
              </Button>
            </form>
          )}
        </CardContent>
      </Card>
    </main>
  );
}

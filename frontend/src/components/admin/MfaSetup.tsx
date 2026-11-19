import { useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { authClient } from "@/lib/auth/client";

type Step =
  { kind: "password" } | { kind: "scan"; uri: string; secret: string; backupCodes: string[] };

/** Required two-step sign-in setup for roles in AUTH_MFA_REQUIRED_ROLES. */
export function MfaSetup({ email, onSignOut }: { email: string | null; onSignOut: () => void }) {
  const queryClient = useQueryClient();
  const [step, setStep] = useState<Step>({ kind: "password" });
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);

  const start = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    const { data, error } = await authClient.twoFactor.enable({ password, method: "totp" });
    setBusy(false);
    setPassword("");
    if (error || !data || data.method !== "totp") {
      toast.error("Your password was not accepted.");
      return;
    }
    const secret = new URL(data.totpURI).searchParams.get("secret") ?? "";
    setStep({ kind: "scan", uri: data.totpURI, secret, backupCodes: data.backupCodes });
  };

  const verify = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    const { error } = await authClient.twoFactor.verifyTotp({ code: code.replace(/\s/g, "") });
    setBusy(false);
    if (error) {
      toast.error("That code was not accepted. Check the time on your phone and try again.");
      return;
    }
    toast.success("Two-step sign-in is set up");
    await queryClient.invalidateQueries({ queryKey: ["admin-session"] });
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-muted/30 px-4">
      <Card className="w-full max-w-lg">
        <CardHeader>
          <CardTitle className="text-xl">Set up two-step sign-in</CardTitle>
          <p className="text-sm text-muted-foreground">
            {email} · Administrator accounts must use an authenticator app (for example Microsoft
            Authenticator or Google Authenticator).
          </p>
        </CardHeader>
        <CardContent className="space-y-4">
          {step.kind === "password" && (
            <form className="space-y-4" onSubmit={start}>
              <div className="space-y-2">
                <Label htmlFor="mfa-password">Confirm your password</Label>
                <Input
                  id="mfa-password"
                  type="password"
                  autoComplete="current-password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
              </div>
              <Button type="submit" className="w-full" disabled={busy}>
                Continue
              </Button>
            </form>
          )}

          {step.kind === "scan" && (
            <form className="space-y-4" onSubmit={verify}>
              <ol className="list-decimal space-y-2 pl-5 text-sm">
                <li>
                  In your authenticator app, add an account and enter this key (or{" "}
                  <a className="underline" href={step.uri}>
                    open it on this device
                  </a>
                  ):
                  <code className="mt-1 block break-all rounded bg-muted px-2 py-1 font-mono text-sm">
                    {step.secret}
                  </code>
                </li>
                <li>
                  Store these one-time backup codes somewhere safe. Each works once if you lose your
                  phone:
                  <code className="mt-1 block whitespace-pre-wrap rounded bg-muted px-2 py-1 font-mono text-xs">
                    {step.backupCodes.join("\n")}
                  </code>
                </li>
                <li>Enter the 6-digit code the app shows.</li>
              </ol>
              <div className="space-y-2">
                <Label htmlFor="mfa-code">6-digit code</Label>
                <Input
                  id="mfa-code"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  required
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                />
              </div>
              <Button type="submit" className="w-full" disabled={busy}>
                Turn on two-step sign-in
              </Button>
            </form>
          )}

          <Button variant="outline" className="w-full" onClick={onSignOut}>
            Sign out
          </Button>
        </CardContent>
      </Card>
    </main>
  );
}

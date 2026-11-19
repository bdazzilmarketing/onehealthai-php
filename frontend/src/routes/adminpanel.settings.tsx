import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";

import { useAdminAccess } from "@/lib/admin-access";
import { useEffect, useState } from "react";
import { toast } from "sonner";

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
import { Skeleton } from "@/components/ui/skeleton";
import { getAdminSettings, saveSetting } from "@/lib/admin.functions";
import { formatMoney } from "@/lib/workbook-types";

const CURRENCIES = [
  { value: "AED", label: "AED — UAE Dirham" },
  { value: "USD", label: "USD — US Dollar" },
  { value: "EUR", label: "EUR — Euro" },
  { value: "GBP", label: "GBP — Pound Sterling" },
  { value: "SAR", label: "SAR — Saudi Riyal" },
  { value: "QAR", label: "QAR — Qatari Riyal" },
];

export const Route = createFileRoute("/adminpanel/settings")({
  component: CalculatorSettings,
});

function CalculatorSettings() {
  const queryClient = useQueryClient();
  const fetchSettings = useServerFn(getAdminSettings);
  const save = useServerFn(saveSetting);
  const { readOnly } = useAdminAccess();

  const [lower, setLower] = useState(20);
  const [upper, setUpper] = useState(20);
  const [currency, setCurrency] = useState("AED");

  const settingsQuery = useQuery({ queryKey: ["settings"], queryFn: () => fetchSettings() });

  useEffect(() => {
    const value = settingsQuery.data?.settings.find((s) => s.key === "calculator_variance")
      ?.value as { lower_percent?: number; upper_percent?: number; currency?: string } | undefined;
    if (value) {
      setLower(Number(value.lower_percent ?? 20));
      setUpper(Number(value.upper_percent ?? 20));
      setCurrency(String(value.currency ?? "AED"));
    }
  }, [settingsQuery.data]);

  const mutation = useMutation({
    mutationFn: () =>
      save({
        data: {
          key: "calculator_variance",
          value: { lower_percent: lower, upper_percent: upper, currency },
        },
      }),
    onSuccess: () => {
      toast.success("Calculator settings saved");
      queryClient.invalidateQueries({ queryKey: ["settings"] });
      queryClient.invalidateQueries({ queryKey: ["admin-dashboard"] });
    },
    onError: (error: Error) => toast.error(error.message),
  });

  if (settingsQuery.isLoading) return <Skeleton className="h-64 w-full" />;

  const example = 1_000_000;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Calculator Settings</h1>
        <p className="text-sm text-muted-foreground">
          The public calculator returns a range instead of the exact internal figure. Every change
          is audited.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Estimate variance</CardTitle>
          <CardDescription>Applied to the deterministic server-side calculation.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="lower">Lower variance %</Label>
              <Input
                id="lower"
                disabled={readOnly}
                type="number"
                min={0}
                max={100}
                value={lower}
                onChange={(e) => setLower(Number(e.target.value))}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="upper">Upper variance %</Label>
              <Input
                id="upper"
                disabled={readOnly}
                type="number"
                min={0}
                max={100}
                value={upper}
                onChange={(e) => setUpper(Number(e.target.value))}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="currency">Currency</Label>
              <Select value={currency} onValueChange={setCurrency} disabled={readOnly}>
                <SelectTrigger id="currency">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {CURRENCIES.map((code) => (
                    <SelectItem key={code.value} value={code.value}>
                      {code.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <p className="text-sm text-muted-foreground">
            Example: an internal calculation of {formatMoney(example, currency)} would be shown
            publicly as {formatMoney(example * (1 - lower / 100), currency)} –{" "}
            {formatMoney(example * (1 + upper / 100), currency)}.
          </p>
          {!readOnly && (
            <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
              Save settings
            </Button>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

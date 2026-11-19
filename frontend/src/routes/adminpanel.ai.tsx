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
import { Textarea } from "@/components/ui/textarea";
import { AiQuotaPanel } from "@/components/admin/ai-quota-panel";
import {
  activatePromptVersion,
  listPromptVersions,
  savePromptVersion,
} from "@/lib/admin.functions";

export const Route = createFileRoute("/adminpanel/ai")({
  component: AiConfiguration,
});

function AiConfiguration() {
  const queryClient = useQueryClient();
  const fetchPrompts = useServerFn(listPromptVersions);
  const savePrompt = useServerFn(savePromptVersion);
  const activate = useServerFn(activatePromptVersion);
  const { readOnly } = useAdminAccess();

  const [promptName, setPromptName] = useState("One Health AI Lab Assistant");
  const [promptText, setPromptText] = useState("");
  const [model, setModel] = useState("gpt-4o-mini");

  const promptsQuery = useQuery({ queryKey: ["prompts"], queryFn: () => fetchPrompts() });
  const active = promptsQuery.data?.find((p) => p.status === "active") ?? null;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ["prompts"] });
    queryClient.invalidateQueries({ queryKey: ["admin-dashboard"] });
  };

  const save = useMutation({
    mutationFn: (activateNow: boolean) =>
      savePrompt({ data: { promptName, promptText, model, activate: activateNow } }),
    onSuccess: () => {
      toast.success("Prompt version saved");
      setPromptText("");
      invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const activateMutation = useMutation({
    mutationFn: (id: string) => activate({ data: { id } }),
    onSuccess: () => {
      toast.success("Prompt version activated");
      invalidate();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">AI Configuration</h1>
        <p className="text-sm text-muted-foreground">
          Version-controlled system prompt storage for the AI assistant. Historical versions are
          never overwritten. The AI never calculates prices.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Active prompt</CardTitle>
          <CardDescription>
            {active
              ? `Version ${active.version_number} · model ${active.model}`
              : "No active prompt"}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <pre className="whitespace-pre-wrap rounded-md bg-muted p-3 text-xs">
            {active?.prompt_text ?? "—"}
          </pre>
        </CardContent>
      </Card>

      {!readOnly && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">New prompt version</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="prompt-name">Prompt name</Label>
                <Input
                  id="prompt-name"
                  value={promptName}
                  onChange={(e) => setPromptName(e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="model">OpenAI model</Label>
                <Input id="model" value={model} onChange={(e) => setModel(e.target.value)} />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="prompt-text">System prompt</Label>
              <Textarea
                id="prompt-text"
                rows={10}
                value={promptText}
                onChange={(e) => setPromptText(e.target.value)}
              />
            </div>
            <div className="flex gap-2">
              <Button
                variant="outline"
                disabled={!promptText || save.isPending}
                onClick={() => save.mutate(false)}
              >
                Save draft version
              </Button>
              <Button disabled={!promptText || save.isPending} onClick={() => save.mutate(true)}>
                Save and activate
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      <AiQuotaPanel readOnly={readOnly} />

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Prompt history</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {promptsQuery.isLoading && <Skeleton className="h-24 w-full" />}
          {(promptsQuery.data ?? []).map((prompt) => (
            <div key={prompt.id} className="rounded-md border p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p className="text-sm font-medium">
                    v{prompt.version_number} — {prompt.prompt_name}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {prompt.model} · created {new Date(prompt.created_at).toLocaleString()}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge variant={prompt.status === "active" ? "default" : "secondary"}>
                    {prompt.status}
                  </Badge>
                  {!readOnly && prompt.status !== "active" && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => activateMutation.mutate(prompt.id)}
                    >
                      Restore / activate
                    </Button>
                  )}
                </div>
              </div>
              <pre className="mt-2 max-h-32 overflow-auto whitespace-pre-wrap text-xs text-muted-foreground">
                {prompt.prompt_text}
              </pre>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}

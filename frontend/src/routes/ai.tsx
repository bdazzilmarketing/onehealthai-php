import { useMutation, useQuery } from "@tanstack/react-query";
import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";

import { AiPickerCard } from "@/components/public/ai-picker-card";
import { Arrow, DisplayHeading, PublicPage, Shell } from "@/components/public/ui";
import {
  finalizeAiSelection,
  getAiAvailability,
  getAiPicker,
  resetAiSession,
  sendAiChoice,
  sendAiMessage,
} from "@/lib/ai.functions";
import {
  AI_PREFILL_STORAGE_KEY,
  type AiChatMessage,
  type AiChoice,
  type AiPicker,
  type AiWorkingState,
} from "@/lib/ai-types";

export const Route = createFileRoute("/ai")({
  head: () => ({
    meta: [
      { title: "AI Laboratory Configuration Assistant | One Lab" },
      {
        name: "description",
        content:
          "Describe your laboratory requirements in your own words and the assistant will build your configuration for the One Lab calculator.",
      },
      { property: "og:title", content: "AI Laboratory Configuration Assistant | One Lab" },
      {
        property: "og:description",
        content: "Conversationally configure your laboratory requirements.",
      },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
  }),
  component: AiModePage,
});

const GREETING =
  "Hello — I can help you configure your laboratory. Which laboratories are you planning? Pick as many as you need below and I'll set them up with you one at a time, or describe them in your own words.";

/** Same top-right diagonal notch used across the public surface. */
const PANEL_CLIP = "polygon(0 0, calc(100% - 1.15rem) 0, 100% 1.15rem, 100% 100%, 0 100%)";
const BUBBLE_CLIP = "polygon(0 0, calc(100% - 0.55rem) 0, 100% 0.55rem, 100% 100%, 0 100%)";
const BTN_CLIP = "polygon(0 0, calc(100% - 0.7rem) 0, 100% 0.7rem, 100% 100%, 0 100%)";

function AiModePage() {
  const navigate = useNavigate();
  const checkAvailability = useServerFn(getAiAvailability);
  const send = useServerFn(sendAiMessage);
  const reset = useServerFn(resetAiSession);
  const finalize = useServerFn(finalizeAiSelection);
  const loadPicker = useServerFn(getAiPicker);
  const choose = useServerFn(sendAiChoice);

  const availability = useQuery({
    queryKey: ["ai-availability"],
    queryFn: () => checkAvailability(),
  });

  const [sessionId, setSessionId] = useState<string | null>(null);
  const [messages, setMessages] = useState<AiChatMessage[]>([
    { role: "assistant", content: GREETING },
  ]);
  const [state, setState] = useState<AiWorkingState | null>(null);
  const [input, setInput] = useState("");
  const [picker, setPicker] = useState<AiPicker | null>(null);
  // Laboratories picked on the cards, set up one at a time in this order.
  const [plan, setPlan] = useState<string[]>([]);
  const [restarts, setRestarts] = useState(0);
  const bottomRef = useRef<HTMLDivElement>(null);
  const latestRef = useRef<HTMLDivElement>(null);
  const available = availability.data?.available === true;

  // A new assistant question is scrolled to its top, so its cards or checklist
  // start in view (scrolling to the very bottom skipped long lists on phones).
  // While the visitor's own message waits for a reply, keep the bottom visible.
  useEffect(() => {
    if (messages.length < 2) return;
    if (messages[messages.length - 1]?.role === "assistant") {
      latestRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    } else {
      bottomRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }, [messages]);

  // Opening cards: the published laboratories (no model call).
  useEffect(() => {
    if (!available || sessionId || picker) return;
    let cancelled = false;
    loadPicker({ data: { sessionId: null } })
      .then((result) => {
        if (!cancelled) setPicker(result.picker);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [available, sessionId, restarts]);

  const turn = useMutation({
    mutationFn: (message: string) => send({ data: { sessionId, message, plan } }),
    onSuccess: (result) => {
      if (!result.ok) {
        toast.error(result.message);
        return;
      }
      setSessionId(result.sessionId);
      setState(result.state);
      setPicker(result.picker ?? null);
      setMessages((prev) => [...prev, { role: "assistant", content: result.reply }]);
    },
    onError: () =>
      toast.error(
        "The assistant is temporarily unavailable. You can try again or continue using Manual Mode.",
      ),
  });

  // A clicked card is applied on the server without calling the AI.
  const pick = useMutation({
    mutationFn: (choice: AiChoice) => choose({ data: { sessionId, choice, plan } }),
    onSuccess: (result) => {
      if (!result.ok) {
        toast.error(result.message);
        return;
      }
      setSessionId(result.sessionId);
      setState(result.state);
      setPicker(result.picker);
      setPlan(result.plan);
      setMessages((prev) => [
        ...prev,
        { role: "user", content: result.echo },
        ...(result.reply ? [{ role: "assistant" as const, content: result.reply }] : []),
      ]);
    },
    onError: () => toast.error("That choice could not be saved. Please try again."),
  });

  const handoff = useMutation({
    mutationFn: () => finalize({ data: { sessionId: sessionId ?? "" } }),
    onSuccess: (result) => {
      if (!result.ok || !result.selection) {
        toast.error(result.message ?? "Nothing to hand over yet.");
        return;
      }
      sessionStorage.setItem(AI_PREFILL_STORAGE_KEY, JSON.stringify(result.selection));
      navigate({ to: "/calculator", search: { prefill: "ai" } });
    },
    onError: () => toast.error("The configuration could not be transferred. Try Manual Mode."),
  });

  function submit() {
    const message = input.trim();
    if (!message || turn.isPending || pick.isPending) return;
    setMessages((prev) => [...prev, { role: "user", content: message }]);
    setInput("");
    turn.mutate(message);
  }

  function startOver() {
    if (sessionId) reset({ data: { sessionId } });
    setSessionId(null);
    setState(null);
    setPicker(null);
    setPlan([]);
    setRestarts((n) => n + 1);
    setMessages([{ role: "assistant", content: GREETING }]);
    setInput("");
  }

  const hasSelections = (state?.labs.length ?? 0) > 0;

  return (
    <PublicPage>

      <main className="relative z-10">
        <Shell className="pb-14 pt-8">
          <DisplayHeading className="max-w-[20ch] text-[clamp(1.9rem,4vw,3rem)] leading-[1.05] text-white">
            Build your lab with AI
          </DisplayHeading>
          <p className="mt-3 max-w-xl text-[0.9375rem] leading-relaxed text-white/60">
            Describe your requirements in your own words. You can review and edit everything before
            any estimate is produced.
          </p>

          {availability.isLoading && (
            <div className="py-16">
              <div className="h-px w-full overflow-hidden bg-white/15">
                <div className="oh-scan h-px w-1/3 bg-white/70" />
              </div>
            </div>
          )}

          {availability.data && !availability.data.available && (
            <div
              style={{ clipPath: PANEL_CLIP }}
              className="mt-10 border border-white/15 bg-white/[0.05] p-6 backdrop-blur-md"
            >
              <p className="text-[0.8125rem] text-white/60">Assistant unavailable</p>
              <p className="mt-3 max-w-md text-sm text-white/80">{availability.data.reason}</p>
              <Link
                to="/calculator"
                search={{}}
                style={{ clipPath: BTN_CLIP }}
                className="group mt-6 inline-flex items-center gap-2 bg-white px-6 py-3 text-[0.8125rem] font-medium text-oh-blue-deep transition-colors duration-200 hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none"
              >
                Continue in Manual Mode
                <Arrow className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none" />
              </Link>
            </div>
          )}

          {availability.data?.available && (
            <div className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
              {/* ------------------------------------------- conversation */}
              <section
                style={{ clipPath: PANEL_CLIP }}
                className="flex min-h-[62vh] flex-col border border-white/15 bg-white/[0.05] backdrop-blur-md lg:min-h-[66vh]"
              >
                <div className="flex items-center gap-3 border-b border-white/10 px-5 py-4">
                  <span className="relative flex h-1.5 w-1.5">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-white/70" />
                    <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-white" />
                  </span>
                  <div className="min-w-0">
                    <p className="font-display text-[0.9375rem] font-medium leading-tight tracking-[-0.01em] text-white">
                      Laboratory assistant
                    </p>
                    <p className="mt-0.5 text-[0.8125rem] text-white/55">
                      {turn.isPending || pick.isPending ? "Typing" : "Online"}
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={startOver}
                    className="ml-auto inline-flex items-center gap-2 text-[0.8125rem] font-medium text-white/60 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none"
                  >
                    Start over
                  </button>
                </div>

                <div
                  className="flex-1 space-y-5 overflow-y-auto px-4 py-6 sm:px-6"
                  aria-live="polite"
                >
                  {messages.map((message, index) => {
                    const isUser = message.role === "user";
                    return (
                      <div
                        key={index}
                        ref={index === messages.length - 1 ? latestRef : undefined}
                        className={`flex scroll-mt-4 ${isUser ? "justify-end" : "justify-start"}`}
                      >
                        <div className="max-w-[85%] sm:max-w-[72%]">
                          <p
                            className={`mb-2 text-[0.8125rem] text-white/45 ${
                              isUser ? "text-right" : ""
                            }`}
                          >
                            {isUser ? "You" : "Assistant"}
                          </p>
                          <div
                            style={{ clipPath: BUBBLE_CLIP }}
                            className={`whitespace-pre-wrap px-4 py-3 text-[0.9375rem] leading-relaxed ${
                              isUser
                                ? "bg-white/90 text-oh-blue-deep"
                                : "border border-white/15 bg-white/[0.06] text-white/85"
                            }`}
                          >
                            {message.content}
                          </div>
                          {!isUser && index === messages.length - 1 && picker && !turn.isPending && (
                            <AiPickerCard
                              picker={picker}
                              disabled={pick.isPending || handoff.isPending}
                              onChoose={(choice) => pick.mutate(choice)}
                              onReview={() => handoff.mutate()}
                            />
                          )}
                        </div>
                      </div>
                    );
                  })}

                  {(turn.isPending || pick.isPending) && (
                    <div className="flex justify-start">
                      <div
                        style={{ clipPath: BUBBLE_CLIP }}
                        className="flex items-center gap-1.5 border border-white/15 bg-white/[0.06] px-4 py-4"
                      >
                        {[0, 1, 2].map((dot) => (
                          <span
                            key={dot}
                            className="h-1.5 w-1.5 animate-bounce rounded-full bg-white/80"
                            style={{ animationDelay: `${dot * 0.15}s` }}
                          />
                        ))}
                      </div>
                    </div>
                  )}
                  <div ref={bottomRef} />
                </div>

                {/* ----------------------------------------------- composer */}
                <div className="border-t border-white/10 px-4 py-5 sm:px-5">
                  <label htmlFor="ai-input" className="sr-only">
                    Describe the laboratory you want to configure
                  </label>
                  <div className="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-3 border-b border-white/20 pb-2 transition-colors focus-within:border-white/60">
                    <textarea
                      id="ai-input"
                      rows={1}
                      value={input}
                      maxLength={2000}
                      placeholder="Pick an option above, or type your answer…"
                      onChange={(e) => setInput(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter" && !e.shiftKey) {
                          e.preventDefault();
                          submit();
                        }
                      }}
                      className="max-h-32 w-full resize-none border-0 bg-transparent py-2 text-[0.9375rem] leading-relaxed text-white placeholder:text-white/35 focus:outline-none"
                    />
                    <button
                      type="button"
                      onClick={submit}
                      disabled={turn.isPending || pick.isPending || input.trim().length === 0}
                      aria-label="Send message"
                      style={{ clipPath: BUBBLE_CLIP }}
                      className="group mb-1 grid h-9 w-9 shrink-0 place-items-center bg-white text-oh-blue-deep transition-colors hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-30 motion-reduce:transition-none"
                    >
                      <Arrow className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none" />
                    </button>
                  </div>
                  <div className="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-[0.8125rem] text-white/50">
                    <span>Enter to send · Shift + Enter for a new line</span>
                    <Link
                      to="/calculator"
                      search={{}}
                      className="group inline-flex items-center gap-2 font-medium transition-colors hover:text-white motion-reduce:transition-none"
                    >
                      Switch to manual configuration
                      <Arrow className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none" />
                    </Link>
                  </div>
                </div>
              </section>

              {/* --------------------------------------- structured state */}
              <aside
                style={{ clipPath: PANEL_CLIP }}
                className="border border-white/15 bg-white/[0.04] p-6 backdrop-blur-md"
              >
                <p className="text-[0.8125rem] text-white/55">Configuration so far</p>

                {!hasSelections && (
                  <p className="mt-6 text-sm text-white/45">Nothing selected yet.</p>
                )}

                <div className="mt-6 space-y-8">
                  {state?.labs.map((lab) => (
                    <div key={lab.labName}>
                      <p className="border-b border-white/20 pb-2 font-display text-[1rem] font-medium leading-tight tracking-[-0.01em] text-white">
                        {lab.labName}
                      </p>
                      <ul className="mt-3 space-y-4">
                        {lab.sections.map((section) => (
                          <li key={section.sectionName} className="text-sm">
                            <p className="font-medium text-white/90">{section.sectionName}</p>

                            <dl className="mt-1 space-y-0.5 text-white/55">
                              {!section.hardwareOnly && (
                                <div className="flex justify-between gap-4">
                                  <dt>Annual volume</dt>
                                  <dd className="tabular-nums">
                                    {section.annualTestVolume.toLocaleString()}
                                  </dd>
                                </div>
                              )}
                              {section.parameterNames.length > 0 && (
                                <div className="flex justify-between gap-4">
                                  <dt>Selected assays</dt>
                                  <dd className="tabular-nums">{section.parameterNames.length}</dd>
                                </div>
                              )}
                              {section.hardwareNames.length > 0 && (
                                <div className="flex justify-between gap-4">
                                  <dt>Equipment</dt>
                                  <dd className="tabular-nums">{section.hardwareNames.length}</dd>
                                </div>
                              )}
                            </dl>
                            <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
                              {section.parameterNames.map(name => <li key={name}>{name}</li>)}
                              {section.includeHardware && section.hardwareNames.map(name => <li key={name}>{name}</li>)}
                            </ul>
                          </li>
                        ))}
                      </ul>
                    </div>
                  ))}

                  {state && state.missingFields.length > 0 && (
                    <div className="border-t border-dashed border-white/20 pt-4">
                      <p className="text-[0.8125rem] font-medium text-white/60">Still needed</p>
                      <ul className="mt-2 space-y-1 text-sm text-white/55">
                        {state.missingFields.map((field) => (
                          <li key={field}>— {field}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>

                <div className="mt-10">
                  <button
                    type="button"
                    disabled={!hasSelections || handoff.isPending}
                    onClick={() => handoff.mutate()}
                    style={{ clipPath: BTN_CLIP }}
                    className="group inline-flex w-full items-center justify-between gap-2 bg-white px-5 py-3 text-[0.8125rem] font-medium text-oh-blue-deep transition-colors duration-200 hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-30 motion-reduce:transition-none"
                  >
                    {handoff.isPending ? "Preparing…" : "Review configuration"}
                    <Arrow className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none" />
                  </button>
                  <p className="mt-4 text-xs leading-relaxed text-white/45">
                    You can review and edit everything before any estimate is produced.
                  </p>
                </div>
              </aside>
            </div>
          )}
        </Shell>
      </main>
    </PublicPage>
  );
}

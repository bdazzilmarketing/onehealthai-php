/**
 * Decorative, self-playing chat preview shown on the landing page.
 *
 * It types out a short scripted exchange between a visitor and the laboratory
 * assistant, then loops. Purely visual — no network, no real AI call.
 */
import { useEffect, useState } from "react";

import { cn } from "@/lib/utils";

type Line = { role: "user" | "assistant"; text: string };

const SCRIPT: Line[] = [
  { role: "user", text: "I'm opening a 400 m² diagnostic lab in Dubai." },
  { role: "assistant", text: "Great — which disciplines do you need? Core lab, POCT, molecular?" },
  { role: "user", text: "Core lab chemistry and immunology, plus POCT." },
  { role: "assistant", text: "Understood. Building your configuration and estimate range now." },
];

const TYPE_MS = 22;
const HOLD_MS = 900;

export function ChatSimulation({ className }: { className?: string }) {
  const [visible, setVisible] = useState<Line[]>([]);
  const [typing, setTyping] = useState("");
  const [index, setIndex] = useState(0);

  useEffect(() => {
    const line = SCRIPT[index % SCRIPT.length]!;
    let char = 0;
    setTyping("");

    const typer = window.setInterval(() => {
      char += 1;
      setTyping(line.text.slice(0, char));
      if (char >= line.text.length) {
        window.clearInterval(typer);
        window.setTimeout(() => {
          const next = index + 1;
          if (next % SCRIPT.length === 0) {
            setVisible([]);
          } else {
            setVisible((prev) => [...prev, line].slice(-2));
          }
          setIndex(next);
        }, HOLD_MS);
      }
    }, TYPE_MS);

    return () => window.clearInterval(typer);
  }, [index]);

  const current = SCRIPT[index % SCRIPT.length]!;

  return (
    <div
      aria-hidden="true"
      style={{ clipPath: PANEL_CLIP }}
      className={cn(
        "w-full max-w-xl border border-white/15 bg-white/[0.05] p-4 backdrop-blur-md",
        className,
      )}
    >
      <div className="flex items-center gap-2 border-b border-white/10 pb-3">
        <span className="relative flex h-1.5 w-1.5">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-white/70" />
          <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-white" />
        </span>
        <span className="text-xs text-white/65">Laboratory assistant</span>
      </div>

      <div className="mt-3 flex min-h-[7.5rem] flex-col justify-end gap-2">
        {visible.map((line, i) => (
          <Bubble key={`${line.role}-${i}`} role={line.role} text={line.text} />
        ))}
        <Bubble role={current.role} text={typing || "…"} />
      </div>
    </div>
  );
}

/** Same top-right diagonal notch used by the public buttons. */
const PANEL_CLIP = "polygon(0 0, calc(100% - 1.15rem) 0, 100% 1.15rem, 100% 100%, 0 100%)";
const BUBBLE_CLIP = "polygon(0 0, calc(100% - 0.55rem) 0, 100% 0.55rem, 100% 100%, 0 100%)";

function Bubble({ role, text }: { role: Line["role"]; text: string }) {
  const isUser = role === "user";
  return (
    <div className={cn("flex", isUser ? "justify-end" : "justify-start")}>
      <span
        style={{ clipPath: BUBBLE_CLIP }}
        className={cn(
          "max-w-[85%] px-3 py-2 text-left text-[0.8125rem] leading-relaxed",
          isUser ? "bg-white/90 text-oh-blue-deep" : "border border-white/15 bg-white/[0.06] text-white/85",
        )}
      >
        {text}
      </span>
    </div>
  );
}

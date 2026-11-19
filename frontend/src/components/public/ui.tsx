/**
 * Public-facing design system for the One Lab AI Lab Calculator.
 *
 * These primitives are used ONLY by the public routes (/, /calculator,
 * /ai). The admin portal keeps the default shadcn styling and must not import
 * from this module.
 *
 * Visual language: dark high-tech surface, glass panels with a top-right
 * diagonal notch, thin light rules, editorial display typography and
 * north-east directional arrows.
 */
import { Link } from "@tanstack/react-router";
import type { ButtonHTMLAttributes, ReactNode } from "react";

import logoUrl from "@/assets/onehealth-logo.png";
import { TechBackground } from "@/components/public/tech-background";
import { cn } from "@/lib/utils";

/* -------------------------------------------------------------------- clips */

/** Panel-scale top-right diagonal notch. */
export const PANEL_CLIP =
  "polygon(0 0, calc(100% - 1.15rem) 0, 100% 1.15rem, 100% 100%, 0 100%)";
/** Button-scale notch. */
export const BTN_CLIP = "polygon(0 0, calc(100% - 0.7rem) 0, 100% 0.7rem, 100% 100%, 0 100%)";
/** Small bubble / chip notch. */
export const BUBBLE_CLIP =
  "polygon(0 0, calc(100% - 0.55rem) 0, 100% 0.55rem, 100% 100%, 0 100%)";

/* ------------------------------------------------------------------ arrow */

export function Arrow({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 16 16"
      aria-hidden="true"
      focusable="false"
      className={cn("h-[1em] w-[1em] shrink-0", className)}
    >
      <path
        d="M4 12 12 4M6 4h6v6"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="square"
      />
    </svg>
  );
}

const ARROW_MOTION =
  "transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none";

/* --------------------------------------------------------------- typography */

export function SectionLabel({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <p className={cn("text-[0.8125rem] font-normal tracking-normal text-white/55", className)}>
      {children}
    </p>
  );
}

export function DisplayHeading({
  children,
  className,
  as: Tag = "h1",
}: {
  children: ReactNode;
  className?: string;
  as?: "h1" | "h2" | "h3";
}) {
  return (
    <Tag
      className={cn(
        "font-display font-medium leading-[1.06] tracking-[-0.02em] text-white",
        "text-[clamp(2rem,5vw,3.75rem)]",
        className,
      )}
    >
      {children}
    </Tag>
  );
}

export function SubHeading({
  children,
  className,
  as: Tag = "h2",
}: {
  children: ReactNode;
  className?: string;
  as?: "h2" | "h3";
}) {
  return (
    <Tag
      className={cn(
        "font-display font-medium leading-[1.15] tracking-[-0.015em] text-white",
        "text-[clamp(1.375rem,2.6vw,2.25rem)]",
        className,
      )}
    >
      {children}
    </Tag>
  );
}

export function SectionDivider({ className }: { className?: string }) {
  return <hr className={cn("border-0 border-t border-white/12", className)} />;
}

/* ------------------------------------------------------------------- panel */

/** Notched glass panel — the standard block used to separate sections. */
export function Panel({
  children,
  className,
  as: Tag = "section",
}: {
  children: ReactNode;
  className?: string;
  as?: "section" | "div" | "aside" | "form";
}) {
  return (
    <Tag
      style={{ clipPath: PANEL_CLIP }}
      className={cn("border border-white/15 bg-white/[0.05] backdrop-blur-md", className)}
    >
      {children}
    </Tag>
  );
}

export function PanelHeader({
  label,
  title,
  description,
  aside,
}: {
  label?: ReactNode;
  title?: ReactNode;
  description?: ReactNode;
  aside?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-4 border-b border-white/10 px-5 py-4 sm:px-6">
      <div className="min-w-0">
        {label && <SectionLabel>{label}</SectionLabel>}
        {title && (
          <p className="font-display text-[1.0625rem] font-medium leading-tight tracking-[-0.01em] text-white">
            {title}
          </p>
        )}
        {description && (
          <p className="mt-2 max-w-md text-[0.8125rem] leading-relaxed text-white/55">
            {description}
          </p>
        )}
      </div>
      {aside}
    </div>
  );
}

/* ------------------------------------------------------------------ buttons */

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { clipped?: boolean };

export function PrimaryButton({ className, clipped = true, children, ...props }: ButtonProps) {
  return (
    <button
      {...props}
      style={clipped ? { clipPath: BTN_CLIP, ...props.style } : props.style}
      className={cn(
        "group inline-flex items-center gap-2.5 bg-white px-5 py-3",
        "text-[0.8125rem] font-medium tracking-normal text-oh-blue-deep",
        "transition-colors duration-200 motion-reduce:transition-none",
        "hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white",
        "disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-white",
        className,
      )}
    >
      {children}
    </button>
  );
}

export function SecondaryButton({ className, clipped = true, children, ...props }: ButtonProps) {
  return (
    <button
      {...props}
      style={clipped ? { clipPath: BTN_CLIP, ...props.style } : props.style}
      className={cn(
        "group inline-flex items-center gap-2.5 border border-white/25 bg-white/[0.06] px-5 py-3",
        "text-[0.8125rem] font-medium tracking-normal text-white backdrop-blur-sm",
        "transition-colors duration-200 motion-reduce:transition-none",
        "hover:border-white/50 hover:bg-white/[0.12]",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white",
        "disabled:cursor-not-allowed disabled:opacity-30",
        className,
      )}
    >
      {children}
    </button>
  );
}

/** Inline text action with the recurring north-east arrow motif. */
export function ArrowAction({
  children,
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...props}
      className={cn(
        "group inline-flex items-center gap-2 text-[0.8125rem] font-medium tracking-normal",
        "text-white/65 transition-colors hover:text-white motion-reduce:transition-none",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white",
        className,
      )}
    >
      {children}
      <Arrow className={ARROW_MOTION} />
    </button>
  );
}

/* ------------------------------------------------------------------- header */

/** Logo on the left, optional "Back to home" action on the right. */
export function PublicHeader({ showBack = true }: { tone?: string; showBack?: boolean }) {
  return (
    <header className="relative z-20">
      <Shell className="flex items-center justify-between gap-4 pt-5 sm:pt-6">
        <Link
          to="/"
          className="inline-block focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white"
        >
          <img
            src={logoUrl}
            alt="One Health"
            className="h-8 w-auto sm:h-9"
            width={1160}
            height={617}
          />
        </Link>
        {showBack ? (
          <Link
            to="/"
            style={{ clipPath: BTN_CLIP }}
            className="group inline-flex items-center gap-2 border border-white/25 bg-white/[0.06] px-4 py-2.5 text-[0.8125rem] font-medium text-white backdrop-blur-sm transition-colors duration-200 hover:border-white/50 hover:bg-white/[0.12] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:transition-none"
          >
            <span className="hidden sm:inline">Back to home</span>
            <span className="sm:hidden">Home</span>
            <Arrow className={ARROW_MOTION} />
          </Link>
        ) : (
          <span />
        )}
      </Shell>
    </header>
  );
}

/* -------------------------------------------------------------- page frame */

export function PublicPage({
  children,
  className,
  showBack = true,
  showHeader = true,
}: {
  children: ReactNode;
  tone?: "light" | "blue";
  className?: string;
  showBack?: boolean;
  headerVariant?: "bar" | "floating";
  showHeader?: boolean;
}) {
  return (
    <div
      className={cn(
        "relative isolate min-h-screen bg-[#02040c] font-body text-white antialiased",
        className,
      )}
    >
      <TechBackground />
      {showHeader ? <PublicHeader showBack={showBack} /> : null}
      <div className="relative z-10">{children}</div>
    </div>
  );
}

export function Shell({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn("mx-auto w-full max-w-[1400px] px-5 sm:px-8", className)}>{children}</div>
  );
}

/* ---------------------------------------------------------------- progress */

export interface ProgressStep {
  id: string;
  label: string;
}

export function ProgressIndicator({
  steps,
  currentId,
  completedIds,
  onSelect,
}: {
  steps: ProgressStep[];
  currentId: string;
  completedIds: string[];
  onSelect?: (id: string) => void;
}) {
  const currentIndex = steps.findIndex((s) => s.id === currentId);
  const current = steps[currentIndex];

  return (
    <nav aria-label="Progress">
      {/* Mobile: compact */}
      <div className="flex items-center justify-between gap-4 px-5 py-3 md:hidden">
        <p className="text-[0.8125rem] font-medium tracking-normal text-white">
          <span className="text-white/50">
            {String(currentIndex + 1).padStart(2, "0")} / {String(steps.length).padStart(2, "0")}
          </span>
          <span className="ml-3">{current?.label}</span>
        </p>
      </div>

      {/* Desktop: full rail */}
      <ol className="hidden md:grid" style={{ gridTemplateColumns: `repeat(${steps.length}, 1fr)` }}>
        {steps.map((step, index) => {
          const isCurrent = step.id === currentId;
          const isDone = completedIds.includes(step.id);
          const clickable = Boolean(onSelect) && isDone && !isCurrent;
          return (
            <li key={step.id} className="border-l border-white/10 first:border-l-0">
              <button
                type="button"
                disabled={!clickable}
                onClick={clickable ? () => onSelect?.(step.id) : undefined}
                aria-current={isCurrent ? "step" : undefined}
                className={cn(
                  "flex w-full items-baseline gap-3 px-4 py-3.5 text-left transition-colors motion-reduce:transition-none",
                  isCurrent ? "text-white" : isDone ? "text-white/60" : "text-white/30",
                  clickable && "hover:text-white",
                  !clickable && "cursor-default",
                )}
              >
                <span className="text-[0.75rem] font-medium tabular-nums text-white/40">
                  {String(index + 1).padStart(2, "0")}
                </span>
                <span className="text-[0.8125rem] font-medium tracking-normal">{step.label}</span>
                {isCurrent && (
                  <span className="ml-auto h-1.5 w-1.5 shrink-0 bg-white" aria-hidden="true" />
                )}
              </button>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

/* --------------------------------------------------------- selection rows */

export function SelectionRow({
  index,
  title,
  description,
  selected,
  onToggle,
  meta,
}: {
  index?: number;
  title: string;
  description?: ReactNode;
  selected: boolean;
  onToggle: () => void;
  meta?: ReactNode;
}) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={selected}
      onClick={onToggle}
      className={cn(
        "group grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-4 border-b border-white/10",
        "px-4 py-5 text-left transition-colors duration-200 motion-reduce:transition-none sm:px-6",
        selected ? "bg-white/[0.14] text-white" : "bg-transparent text-white hover:bg-white/[0.06]",
        "focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-white",
      )}
    >
      <span className="w-7 text-[0.75rem] font-medium tabular-nums text-white/40">
        {index !== undefined ? String(index + 1).padStart(2, "0") : ""}
      </span>
      <span className="min-w-0">
        <span className="block font-display text-[clamp(1.05rem,2vw,1.375rem)] font-medium leading-tight tracking-[-0.01em]">
          {title}
        </span>
        {description && <span className="mt-1 block text-sm text-white/55">{description}</span>}
      </span>
      <span className="flex shrink-0 items-center gap-4">
        {meta}
        <span
          className={cn(
            "hidden text-[0.8125rem] font-medium tracking-normal sm:inline",
            selected ? "text-white" : "text-white/50",
          )}
        >
          {selected ? "Selected" : "Select"}
        </span>
        <span
          aria-hidden="true"
          className={cn(
            "grid h-6 w-6 shrink-0 place-items-center border transition-colors motion-reduce:transition-none",
            selected ? "border-white bg-white" : "border-white/35 group-hover:border-white/70",
          )}
        >
          {selected && (
            <svg viewBox="0 0 12 12" className="h-3 w-3 text-oh-blue-deep">
              <path
                d="M2 6.5 4.7 9 10 3.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="square"
              />
            </svg>
          )}
        </span>
      </span>
    </button>
  );
}

/** Compact checkbox row used for long test / hardware lists. */
export function CheckRow({
  label,
  note,
  checked,
  onToggle,
}: {
  label: string;
  note?: string | null;
  checked: boolean;
  onToggle: () => void;
}) {
  return (
    <label
      className={cn(
        "group flex cursor-pointer items-start gap-3 border-b border-white/10 py-2.5 pr-2 pl-1",
        "transition-colors hover:bg-white/[0.06] motion-reduce:transition-none",
        checked && "bg-white/[0.1]",
      )}
    >
      <span
        aria-hidden="true"
        className={cn(
          "mt-0.5 grid h-4 w-4 shrink-0 place-items-center border transition-colors duration-150 motion-reduce:transition-none",
          checked ? "border-white bg-white" : "border-white/35 group-hover:border-white/70",
        )}
      >
        {checked && (
          <svg
            viewBox="0 0 12 12"
            className="h-2.5 w-2.5 text-oh-blue-deep"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="square"
          >
            <path d="M2 6.5 4.7 9 10 3.5" />
          </svg>
        )}
      </span>
      <input type="checkbox" checked={checked} onChange={onToggle} className="sr-only" />
      <span className="min-w-0 text-sm leading-snug text-white/85">
        {label}
        {note && <span className="block text-xs text-white/45">{note}</span>}
      </span>
    </label>
  );
}

/* ------------------------------------------------------------------- forms */

export function FormField({
  id,
  label,
  error,
  hint,
  children,
}: {
  id: string;
  label: string;
  error?: string | undefined;
  hint?: string | undefined;
  children: ReactNode;
}) {
  return (
    <div className="space-y-2">
      <label
        htmlFor={id}
        className="block text-[0.8125rem] font-normal tracking-normal text-white/55"
      >
        {label}
      </label>
      {children}
      {hint && !error && <p className="text-xs text-white/45">{hint}</p>}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-xs font-medium text-red-300">
          {error}
        </p>
      )}
    </div>
  );
}

export const editorialInput = cn(
  "w-full border-0 border-b border-white/25 bg-transparent px-0 py-2.5",
  "text-base text-white placeholder:text-white/35",
  "transition-colors duration-200 motion-reduce:transition-none",
  "focus:border-white/70 focus:outline-none",
  "aria-[invalid=true]:border-red-400",
);

/* ------------------------------------------------------------ review rows */

export function ReviewRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-4 border-b border-white/10 py-2">
      <dt className="text-[0.8125rem] tracking-normal text-white/55">{label}</dt>
      <dd className="text-sm font-medium tabular-nums text-white">{value}</dd>
    </div>
  );
}

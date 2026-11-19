import { useMemo, useState } from "react";

import { Arrow, CheckRow } from "@/components/public/ui";
import type { AiChoice, AiPicker, AiPickerOption } from "@/lib/ai-types";

const CARD_CLIP = "polygon(0 0, calc(100% - 0.55rem) 0, 100% 0.55rem, 100% 100%, 0 100%)";
const VOLUME_PRESETS = [5_000, 10_000, 25_000, 50_000, 100_000, 250_000];
const SEARCH_THRESHOLD = 10;

interface Props {
  picker: AiPicker;
  disabled: boolean;
  onChoose: (choice: AiChoice) => void;
  onReview: () => void;
}

/**
 * Clickable answers under the assistant's latest question. Every option comes
 * from the published catalog via the server; a click never reaches the model.
 */
export function AiPickerCard({ picker, disabled, onChoose, onReview }: Props) {
  // Re-mount per step so checklist and input state never leak between questions.
  const key = `${picker.step}:${picker.labId ?? ""}:${picker.subLabId ?? ""}`;
  return (
    <div className="mt-3" key={key}>
      {(picker.step === "lab" || picker.step === "section") && (
        <ChoiceCards picker={picker} disabled={disabled} onChoose={onChoose} />
      )}
      {(picker.step === "hardware" || picker.step === "tests") && (
        <Checklist picker={picker} disabled={disabled} onChoose={onChoose} />
      )}
      {picker.step === "volume" && (
        <VolumeChoice picker={picker} disabled={disabled} onChoose={onChoose} />
      )}
      {picker.step === "done" && (
        <DoneActions picker={picker} disabled={disabled} onChoose={onChoose} onReview={onReview} />
      )}
    </div>
  );
}

function ChoiceCards({ picker, disabled, onChoose }: Omit<Props, "onReview">) {
  // Several laboratories (or sections) can be picked at once, like Manual Mode.
  const [chosen, setChosen] = useState<string[]>([]);
  const isLab = picker.step === "lab";
  const toggle = (id: string) =>
    setChosen((list) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]));
  const noun = isLab
    ? chosen.length === 1
      ? "laboratory"
      : "laboratories"
    : chosen.length === 1
      ? "section"
      : "sections";
  const submit = () =>
    onChoose(
      isLab
        ? { step: "labs", labIds: chosen }
        : {
            step: "sections",
            labId: picker.labId ?? "",
            subLabIds: chosen.map((id) => id || null),
          },
    );

  return (
    <div className="space-y-2">
      <div className="grid gap-2 sm:grid-cols-2" role="group" aria-label={picker.prompt}>
        {picker.options.map((option, index) => {
          const added = Boolean(option.selected);
          const active = added || chosen.includes(option.id);
          return (
            <button
              key={option.id || index}
              type="button"
              disabled={disabled || added}
              aria-pressed={active}
              onClick={() => toggle(option.id)}
              style={{ clipPath: CARD_CLIP }}
              className={`group flex items-start gap-3 border px-4 py-3 text-left transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-white disabled:cursor-not-allowed motion-reduce:transition-none ${
                active
                  ? "border-white/70 bg-white/[0.16]"
                  : "border-white/15 bg-white/[0.06] hover:border-white/40 hover:bg-white/[0.12]"
              } ${added ? "opacity-60" : ""}`}
            >
              <span
                aria-hidden="true"
                className={`grid h-6 w-6 shrink-0 place-items-center text-[0.75rem] font-semibold tabular-nums transition-colors motion-reduce:transition-none ${
                  active ? "bg-white text-oh-blue-deep" : "bg-white/10 text-white/80"
                }`}
              >
                {active ? (
                  <svg
                    viewBox="0 0 12 12"
                    className="h-3 w-3"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="square"
                  >
                    <path d="M2 6.5 4.7 9 10 3.5" />
                  </svg>
                ) : (
                  index + 1
                )}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-[0.9375rem] font-medium leading-snug text-white">
                  {option.label}
                </span>
                {option.hint && (
                  <span className="mt-0.5 block text-xs text-white/50">{option.hint}</span>
                )}
              </span>
              {added && <span className="shrink-0 text-xs text-white/55">Added</span>}
            </button>
          );
        })}
      </div>
      <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
        <button
          type="button"
          disabled={disabled || chosen.length === 0}
          onClick={submit}
          style={{ clipPath: CARD_CLIP }}
          className="group inline-flex flex-1 items-center justify-between gap-2 bg-white px-4 py-2.5 text-[0.8125rem] font-medium text-oh-blue-deep transition-colors hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-30 motion-reduce:transition-none"
        >
          {chosen.length === 0
            ? isLab
              ? "Pick one or more laboratories"
              : "Pick one or more sections"
            : `${isLab ? "Set up" : "Continue with"} ${chosen.length} ${noun}`}
          <Arrow />
        </button>
        {picker.canSkipLab && picker.labId && (
          <button
            type="button"
            disabled={disabled}
            onClick={() => onChoose({ step: "skip_lab", labId: picker.labId ?? "" })}
            className="text-[0.8125rem] font-medium text-white/55 transition-colors hover:text-white disabled:opacity-40"
          >
            Skip this laboratory
          </button>
        )}
      </div>
    </div>
  );
}

function Checklist({ picker, disabled, onChoose }: Omit<Props, "onReview">) {
  const [chosen, setChosen] = useState<string[]>(() =>
    picker.options.filter((o) => o.selected).map((o) => o.id),
  );
  const [query, setQuery] = useState("");
  const narrowed = picker.step === "tests" && picker.hardwareNames.length > 0;
  const [showAll, setShowAll] = useState(!narrowed);

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return picker.options.filter(
      (o) =>
        (showAll || o.matchesHardware !== false || chosen.includes(o.id)) &&
        (q === "" || o.label.toLowerCase().includes(q)),
    );
  }, [picker.options, query, showAll, chosen]);

  const groups = useMemo(() => {
    const map = new Map<string, AiPickerOption[]>();
    for (const option of visible) {
      const heading = option.group ?? "";
      map.set(heading, [...(map.get(heading) ?? []), option]);
    }
    return [...map.entries()];
  }, [visible]);
  const hasHeadings = groups.some(([heading]) => heading !== "");

  const toggle = (id: string) =>
    setChosen((list) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]));
  const setMany = (ids: string[], on: boolean) =>
    setChosen((list) =>
      on ? [...new Set([...list, ...ids])] : list.filter((x) => !ids.includes(x)),
    );
  const allVisibleChosen = visible.length > 0 && visible.every((o) => chosen.includes(o.id));
  const noun = picker.step === "tests" ? "test" : "machine";

  return (
    <div style={{ clipPath: CARD_CLIP }} className="border border-white/15 bg-white/[0.04] p-3">
      {picker.options.length > SEARCH_THRESHOLD && (
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={picker.step === "tests" ? "Search tests…" : "Search equipment…"}
          aria-label={picker.step === "tests" ? "Search tests" : "Search equipment"}
          className="mb-2 w-full border-0 border-b border-white/20 bg-transparent px-1 py-2 text-sm text-white placeholder:text-white/35 focus:border-white/60 focus:outline-none"
        />
      )}
      <div className="flex flex-wrap items-center justify-between gap-2 px-1 pb-2 text-xs text-white/55">
        <span>
          {narrowed && !showAll
            ? `Tests for ${picker.hardwareNames.join(", ")}`
            : `${visible.length} ${visible.length === 1 ? noun : `${noun}s`}`}
        </span>
        <span className="flex gap-4">
          {narrowed && (
            <button
              type="button"
              className="font-medium hover:text-white"
              onClick={() => setShowAll((v) => !v)}
            >
              {showAll ? "Only tests for my equipment" : "Show all tests"}
            </button>
          )}
          {visible.length > 1 && (
            <button
              type="button"
              className="font-medium hover:text-white"
              onClick={() =>
                setMany(
                  visible.map((o) => o.id),
                  !allVisibleChosen,
                )
              }
            >
              {allVisibleChosen ? "Clear" : "Select all"}
            </button>
          )}
        </span>
      </div>
      <div className="max-h-72 overflow-y-auto">
        {groups.map(([heading, options]) => (
          <div key={heading || "-"}>
            {hasHeadings && (
              <p className="sticky top-0 z-10 bg-oh-blue-deep/90 px-1 pt-3 pb-1 text-[0.75rem] font-medium text-white/60 backdrop-blur">
                {heading || "Other"}
              </p>
            )}
            {options.map((option) => (
              <CheckRow
                key={option.id}
                label={option.label}
                checked={chosen.includes(option.id)}
                onToggle={() => toggle(option.id)}
              />
            ))}
          </div>
        ))}
        {visible.length === 0 && <p className="px-1 py-3 text-sm text-white/45">No matches.</p>}
      </div>
      <button
        type="button"
        disabled={disabled || (picker.step === "tests" && chosen.length === 0)}
        onClick={() =>
          onChoose({
            step: picker.step === "tests" ? "tests" : "hardware",
            labId: picker.labId ?? "",
            subLabId: picker.subLabId,
            ids: chosen,
          })
        }
        style={{ clipPath: CARD_CLIP }}
        className="group mt-3 inline-flex w-full items-center justify-between gap-2 bg-white px-4 py-2.5 text-[0.8125rem] font-medium text-oh-blue-deep transition-colors hover:bg-white/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-30 motion-reduce:transition-none"
      >
        {chosen.length === 0
          ? picker.step === "hardware" && !picker.options.some((o) => o.selected)
            ? "Skip equipment"
            : picker.step === "hardware"
              ? "Continue without equipment"
              : "Choose at least one test"
          : `Continue with ${chosen.length} ${chosen.length === 1 ? noun : `${noun}s`}`}
        <Arrow />
      </button>
    </div>
  );
}

function VolumeChoice({ picker, disabled, onChoose }: Omit<Props, "onReview">) {
  const [value, setValue] = useState<string>(
    picker.currentVolume ? String(picker.currentVolume) : "",
  );
  const choose = (volume: number) =>
    onChoose({ step: "volume", labId: picker.labId ?? "", subLabId: picker.subLabId, volume });
  const typed = Number(value);
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-3 gap-2">
        {VOLUME_PRESETS.map((preset) => (
          <button
            key={preset}
            type="button"
            disabled={disabled}
            onClick={() => choose(preset)}
            style={{ clipPath: CARD_CLIP }}
            className={`border px-3 py-2.5 text-sm font-medium tabular-nums transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-white disabled:opacity-40 motion-reduce:transition-none ${
              picker.currentVolume === preset
                ? "border-white bg-white text-oh-blue-deep"
                : "border-white/15 bg-white/[0.06] text-white hover:border-white/40 hover:bg-white/[0.12]"
            }`}
          >
            {preset.toLocaleString("en-US")}
          </button>
        ))}
      </div>
      <form
        className="flex items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          if (Number.isFinite(typed) && typed > 0) choose(typed);
        }}
      >
        <input
          type="number"
          min={1}
          inputMode="numeric"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder="Other amount per year"
          aria-label="Annual test volume"
          className="w-full border-0 border-b border-white/25 bg-transparent px-1 py-2 text-sm tabular-nums text-white placeholder:text-white/35 focus:border-white/70 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
        />
        <button
          type="submit"
          disabled={disabled || !(Number.isFinite(typed) && typed > 0)}
          style={{ clipPath: CARD_CLIP }}
          className="shrink-0 bg-white px-4 py-2 text-[0.8125rem] font-medium text-oh-blue-deep disabled:opacity-30"
        >
          Save
        </button>
      </form>
    </div>
  );
}

function DoneActions({ picker, disabled, onChoose, onReview }: Props) {
  const actions = [
    picker.canReview && { label: "Review configuration", primary: true, run: onReview },
    picker.canAddSection &&
      picker.labId && {
        label: "Add another section",
        primary: false,
        run: () => onChoose({ step: "add_section", labId: picker.labId ?? "" }),
      },
    { label: "Add another laboratory", primary: false, run: () => onChoose({ step: "add_lab" }) },
  ].filter(Boolean) as { label: string; primary: boolean; run: () => void }[];
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      {actions.map((action) => (
        <button
          key={action.label}
          type="button"
          disabled={disabled}
          onClick={action.run}
          style={{ clipPath: CARD_CLIP }}
          className={`group inline-flex items-center justify-between gap-2 px-4 py-3 text-[0.8125rem] font-medium transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-white disabled:opacity-40 motion-reduce:transition-none ${
            action.primary
              ? "bg-white text-oh-blue-deep hover:bg-white/90 sm:col-span-2"
              : "border border-white/15 bg-white/[0.06] text-white hover:border-white/40 hover:bg-white/[0.12]"
          }`}
        >
          {action.label}
          <Arrow />
        </button>
      ))}
    </div>
  );
}

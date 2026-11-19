import { useMemo, useState } from "react";

import { CheckRow, SectionLabel } from "@/components/public/ui";
import type { PublicSection } from "@/lib/calculator-types";

export interface SectionState {
  annualTestVolume: number | "";
  includeHardware: boolean;
  hardwareIds: string[];
  parameterIds: string[];
  openGroups: string[];
}

export const emptySectionState = (): SectionState => ({
  annualTestVolume: "",
  includeHardware: false,
  hardwareIds: [],
  parameterIds: [],
  openGroups: [],
});

interface Props {
  section: PublicSection;
  state: SectionState;
  onChange: (next: SectionState) => void;
}

const toggle = (list: string[], id: string) =>
  list.includes(id) ? list.filter((x) => x !== id) : [...list, id];

function Disclosure({
  open,
  onToggle,
  title,
  count,
  children,
}: {
  open: boolean;
  onToggle: () => void;
  title: string;
  count?: number;
  children: React.ReactNode;
}) {
  return (
    <div className="border-b border-white/12">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        className="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-4 py-4 text-left transition-colors hover:text-white focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-white motion-reduce:transition-none"
      >
        <span className="min-w-0 truncate text-[0.8125rem] font-medium tracking-normal">
          {title}
        </span>
        <span className="flex shrink-0 items-center gap-4">
          {count !== undefined && count > 0 && (
            <span className="bg-white px-2 py-0.5 text-[0.8125rem] font-semibold tabular-nums text-oh-blue-deep">
              {count}
            </span>
          )}
          <span
            aria-hidden="true"
            className={`text-white/50 transition-transform duration-200 motion-reduce:transition-none ${open ? "rotate-45" : ""}`}
          >
            <svg viewBox="0 0 12 12" className="h-3 w-3">
              <path d="M6 0v12M0 6h12" stroke="currentColor" strokeWidth="1.4" />
            </svg>
          </span>
        </span>
      </button>
      {open && <div className="pb-6">{children}</div>}
    </div>
  );
}

export function SectionConfigurator({ section, state, onChange }: Props) {
  const [filter, setFilter] = useState("");
  const [openCategories, setOpenCategories] = useState<string[]>([]);

  const parameterById = useMemo(
    () => new Map(section.parameters.map((p) => [p.id, p])),
    [section.parameters],
  );

  const hardwareByCategory = useMemo(() => {
    const map = new Map<string, typeof section.hardware>();
    for (const item of section.hardware) {
      const key = item.categoryId ?? "uncategorised";
      map.set(key, [...(map.get(key) ?? []), item]);
    }
    return map;
  }, [section.hardware]);

  const ungrouped = section.ungroupedParameterIds
    .map((id) => parameterById.get(id))
    .filter(Boolean) as PublicSection["parameters"];

  const query = filter.trim().toLowerCase();
  const matches = (name: string) => query === "" || name.toLowerCase().includes(query);

  function selectHardware(id: string) {
    const hardwareIds = toggle(state.hardwareIds, id);
    // Selecting hardware auto-expands the Parameter Group it points to.
    // Shared groups are expanded once — group ids are unique.
    const groupId = section.hardware.find((h) => h.id === id)?.groupId ?? null;
    const openGroups =
      groupId && hardwareIds.includes(id) && !state.openGroups.includes(groupId)
        ? [...state.openGroups, groupId]
        : state.openGroups;
    onChange({ ...state, hardwareIds, openGroups });
  }

  function setParameters(ids: string[]) {
    onChange({ ...state, parameterIds: Array.from(new Set(ids)) });
  }

  const toggleGroupOpen = (id: string) =>
    onChange({ ...state, openGroups: toggle(state.openGroups, id) });

  return (
    <div className="space-y-12">
      {/* ------------------------------------------------------ annual volume */}
      {!section.hardwareOnly && (
        <div className="grid gap-6 border-b border-white/12 pb-10 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:gap-16">
          <div>
            <SectionLabel>Annual test volume</SectionLabel>
            <p className="mt-3 max-w-xs text-xs leading-relaxed text-white/55">
              Estimated number of tests performed per year for {section.name}. Enter an annual
              figure, not a monthly one.
            </p>
          </div>
          <div>
            <input
              id={`vol-${section.key}`}
              aria-label={`Annual test volume for ${section.name}`}
              type="number"
              min={0}
              inputMode="numeric"
              placeholder="10,000"
              value={state.annualTestVolume}
              onChange={(e) =>
                onChange({
                  ...state,
                  annualTestVolume: e.target.value === "" ? "" : Number(e.target.value),
                })
              }
              className="w-full max-w-md border-0 border-b border-white/25 bg-transparent px-0 pb-2 font-display text-[clamp(2rem,5vw,3.5rem)] leading-none tracking-[-0.02em] tabular-nums text-white placeholder:text-white/25 focus:border-white/70 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            />
          </div>
        </div>
      )}

      {/* ----------------------------------------------------------- hardware */}
      {section.supportsHardware && (
        <div className="grid gap-6 border-b border-white/12 pb-10 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:gap-16">
          <div>
            <SectionLabel>Equipment</SectionLabel>
            <p className="mt-3 max-w-xs text-xs leading-relaxed text-white/55">
              Include equipment for this section in the estimate?
            </p>
          </div>

          <div>
            <fieldset>
              <legend className="sr-only">Include equipment for {section.name}</legend>
              <div className="flex gap-px bg-white/12">
                {[
                  { value: true, label: "Yes, include equipment" },
                  { value: false, label: "No equipment" },
                ].map((option) => {
                  const active = state.includeHardware === option.value;
                  return (
                    <button
                      key={String(option.value)}
                      type="button"
                      role="radio"
                      aria-checked={active}
                      onClick={() =>
                        onChange({
                          ...state,
                          includeHardware: option.value,
                          hardwareIds: option.value ? state.hardwareIds : [],
                        })
                      }
                      className={`flex-1 px-5 py-4 text-[0.8125rem] font-medium tracking-normal transition-colors motion-reduce:transition-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-white ${
                        active
                          ? "bg-white text-oh-blue-deep"
                          : "bg-white/[0.06] text-white/60 hover:bg-white/[0.12] hover:text-white"
                      }`}
                    >
                      {option.label}
                    </button>
                  );
                })}
              </div>
            </fieldset>

            {state.includeHardware && (
              <div className="mt-8 border-t border-white/12">
                {section.categories.length > 0
                  ? section.categories.map((category) => {
                      const items = hardwareByCategory.get(category.id) ?? [];
                      if (items.length === 0) return null;
                      const selected = items.filter((i) =>
                        state.hardwareIds.includes(i.id),
                      ).length;
                      return (
                        <Disclosure
                          key={category.id}
                          open={openCategories.includes(category.id)}
                          onToggle={() =>
                            setOpenCategories((prev) => toggle(prev, category.id))
                          }
                          title={category.name}
                          count={selected}
                        >
                          <div className="grid sm:grid-cols-2 sm:gap-x-10">
                            {items.map((item) => (
                              <CheckRow
                                key={item.id}
                                label={item.name}
                                checked={state.hardwareIds.includes(item.id)}
                                onToggle={() => selectHardware(item.id)}
                              />
                            ))}
                          </div>
                        </Disclosure>
                      );
                    })
                  : (
                      <div className="grid sm:grid-cols-2 sm:gap-x-10">
                        {section.hardware.map((item) => (
                          <CheckRow
                            key={item.id}
                            label={item.name}
                            checked={state.hardwareIds.includes(item.id)}
                            onToggle={() => selectHardware(item.id)}
                          />
                        ))}
                      </div>
                    )}
              </div>
            )}
          </div>
        </div>
      )}

      {/* ---------------------------------------------------------- parameters */}
      {!section.hardwareOnly && (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:gap-16">
          <div>
            <SectionLabel>Tests</SectionLabel>
            <p className="mt-3 text-[0.8125rem] font-semibold tabular-nums">
              {state.parameterIds.length}{" "}
              {state.parameterIds.length === 1 ? "test selected" : "tests selected"}
            </p>
            {section.parameters.length > 12 && (
              <input
                type="search"
                value={filter}
                aria-label={`Filter tests in ${section.name}`}
                placeholder="Filter tests…"
                onChange={(e) => setFilter(e.target.value)}
                className="mt-5 w-full max-w-xs border-0 border-b border-white/25 bg-transparent px-0 py-2 text-sm placeholder:text-white/40 focus:border-white/70 focus:outline-none"
              />
            )}
          </div>

          <div className="border-t border-white/12">
            {section.groups.map((group) => {
              const visibleIds = group.parameterIds.filter((id) =>
                matches(parameterById.get(id)?.name ?? ""),
              );
              if (visibleIds.length === 0) return null;
              const selected = group.parameterIds.filter((id) =>
                state.parameterIds.includes(id),
              ).length;
              const allSelected = selected === group.parameterIds.length;
              return (
                <Disclosure
                  key={group.id}
                  open={state.openGroups.includes(group.id) || query !== ""}
                  onToggle={() => toggleGroupOpen(group.id)}
                  title={group.name}
                  count={selected}
                >
                  <button
                    type="button"
                    onClick={() =>
                      setParameters(
                        allSelected
                          ? state.parameterIds.filter((id) => !group.parameterIds.includes(id))
                          : [...state.parameterIds, ...group.parameterIds],
                      )
                    }
                    className="mb-3 text-[0.8125rem] font-medium tracking-normal text-white underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                  >
                    {allSelected ? "Clear group" : "Select all in group"}
                  </button>
                  <div className="grid sm:grid-cols-2 sm:gap-x-10">
                    {visibleIds.map((id) => {
                      const parameter = parameterById.get(id);
                      if (!parameter) return null;
                      return (
                        <CheckRow
                          key={id}
                          label={parameter.name}
                          note={parameter.notes}
                          checked={state.parameterIds.includes(id)}
                          onToggle={() => setParameters(toggle(state.parameterIds, id))}
                        />
                      );
                    })}
                  </div>
                </Disclosure>
              );
            })}

            {ungrouped.length > 0 && (
              <Disclosure
                open={
                  state.openGroups.includes(`${section.key}-ungrouped`) ||
                  section.groups.length === 0 ||
                  query !== ""
                }
                onToggle={() => toggleGroupOpen(`${section.key}-ungrouped`)}
                title={section.groups.length === 0 ? "Available tests" : "Other tests"}
                count={
                  ungrouped.filter((p) => state.parameterIds.includes(p.id)).length
                }
              >
                <div className="grid sm:grid-cols-2 sm:gap-x-10">
                  {ungrouped
                    .filter((parameter) => matches(parameter.name))
                    .map((parameter) => (
                      <CheckRow
                        key={parameter.id}
                        label={parameter.name}
                        note={parameter.notes}
                        checked={state.parameterIds.includes(parameter.id)}
                        onToggle={() => setParameters(toggle(state.parameterIds, parameter.id))}
                      />
                    ))}
                </div>
              </Disclosure>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

import { useMutation, useQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import {
  SectionConfigurator,
  emptySectionState,
  type SectionState,
} from "@/components/calculator/section-configurator";
import {
  Arrow,
  ArrowAction,
  DisplayHeading,
  Panel,
  PanelHeader,
  PrimaryButton,
  ProgressIndicator,
  PublicPage,
  ReviewRow,
  SecondaryButton,
  SectionLabel,
  SelectionRow,
  Shell,
  SubHeading,
} from "@/components/public/ui";

import { EMPTY_LEAD, LeadForm } from "@/components/public/lead-form";
import { AI_PREFILL_STORAGE_KEY } from "@/lib/ai-types";
import { calculateEstimate, getCalculatorCatalog } from "@/lib/calculator.functions";
import type {
  CalculatorSelection,
  PublicCatalog,
  PublicEstimate,
  PublicLab,
  PublicSection,
  SelectionSection,
} from "@/lib/calculator-types";
import {
  normalizeLeadInput,
  validateLead,
  type LeadFieldErrors,
  type LeadInput,
} from "@/lib/lead-types";

const ARROW =
  "transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none";

export const Route = createFileRoute("/calculator")({
  validateSearch: (search: Record<string, unknown>): { prefill?: "ai" } =>
    search["prefill"] === "ai" ? { prefill: "ai" } : {},
  head: () => ({
    meta: [
      { title: "Laboratory Investment Calculator | One Health" },
      {
        name: "description",
        content:
          "Configure your Core Lab, POCT, Molecular, Microbiology and Genetics requirements and receive an estimated laboratory investment range.",
      },
      { property: "og:title", content: "Laboratory Investment Calculator | One Health" },
      {
        property: "og:description",
        content:
          "Build your laboratory configuration and receive an estimated investment range.",
      },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
  }),
  component: CalculatorPage,
});

type Step = "labs" | "scope" | "configure" | "review" | "lead" | "result";

const money = (value: number, currency: string) =>
  `${currency} ${Math.round(value).toLocaleString()}`;

const STEP_ORDER: Step[] = ["labs", "scope", "configure", "review", "lead", "result"];

function CalculatorPage() {
  const { prefill } = Route.useSearch();
  const fetchCatalog = useServerFn(getCalculatorCatalog);
  const calculate = useServerFn(calculateEstimate);

  const catalogQuery = useQuery<PublicCatalog>({
    queryKey: ["calculator-catalog"],
    queryFn: () => fetchCatalog(),
  });

  const [step, setStep] = useState<Step>("labs");
  const [labIds, setLabIds] = useState<string[]>([]);
  const [sectionKeys, setSectionKeys] = useState<string[]>([]);
  const [sectionState, setSectionState] = useState<Record<string, SectionState>>({});
  const [estimate, setEstimate] = useState<PublicEstimate | null>(null);
  // Lead details live here (not in the form) so Back/forward keeps them.
  const [lead, setLead] = useState<LeadInput>(EMPTY_LEAD);
  const [leadErrors, setLeadErrors] = useState<LeadFieldErrors>({});

  const catalog = catalogQuery.data;
  const selectedLabs = useMemo(
    () => (catalog?.labs ?? []).filter((l) => labIds.includes(l.labId)),
    [catalog, labIds],
  );

  const activeSections = useMemo(() => {
    const list: { lab: PublicLab; section: PublicSection }[] = [];
    for (const lab of selectedLabs) {
      for (const section of lab.sections) {
        if (sectionKeys.includes(section.key)) list.push({ lab, section });
      }
    }
    return list;
  }, [selectedLabs, sectionKeys]);

  const stateFor = (key: string) => sectionState[key] ?? emptySectionState();

  // AI Mode handoff: the validated Stage 2 selection simply becomes the
  // starting state of this existing Manual Calculator. No separate AI logic.
  const prefillApplied = useRef(false);
  useEffect(() => {
    if (prefill !== "ai" || prefillApplied.current || !catalog) return;
    prefillApplied.current = true;
    const raw = sessionStorage.getItem(AI_PREFILL_STORAGE_KEY);
    sessionStorage.removeItem(AI_PREFILL_STORAGE_KEY);
    if (!raw) return;

    let selection: CalculatorSelection;
    try {
      selection = JSON.parse(raw) as CalculatorSelection;
    } catch {
      return;
    }

    const nextLabIds: string[] = [];
    const nextSectionKeys: string[] = [];
    const nextState: Record<string, SectionState> = {};

    for (const lab of selection.labs ?? []) {
      const catalogLab = catalog.labs.find((l) => l.labId === lab.labId);
      if (!catalogLab) continue;
      nextLabIds.push(catalogLab.labId);
      for (const section of lab.sections ?? []) {
        const catalogSection = catalogLab.flat
          ? catalogLab.sections[0]
          : catalogLab.sections.find((s) => s.subLabId === section.subLabId);
        if (!catalogSection) continue;
        nextSectionKeys.push(catalogSection.key);
        nextState[catalogSection.key] = {
          annualTestVolume: section.annualTestVolume > 0 ? section.annualTestVolume : "",
          includeHardware: section.includeHardware,
          hardwareIds: [...section.selectedHardwareIds],
          parameterIds: [...section.selectedParameterIds],
          openGroups: [],
        };
      }
    }

    if (nextLabIds.length === 0) return;
    setLabIds(nextLabIds);
    setSectionKeys(nextSectionKeys);
    setSectionState(nextState);
    setStep("configure");
  }, [prefill, catalog]);

  function toggleLab(lab: PublicLab) {
    const on = labIds.includes(lab.labId);
    setLabIds(on ? labIds.filter((id) => id !== lab.labId) : [...labIds, lab.labId]);
    const keys = lab.sections.map((s) => s.key);
    if (on) {
      setSectionKeys(sectionKeys.filter((k) => !keys.includes(k)));
    } else if (lab.flat || lab.sections.length === 1) {
      setSectionKeys([...sectionKeys, ...keys]);
    }
  }

  function toggleSection(key: string) {
    setSectionKeys(
      sectionKeys.includes(key) ? sectionKeys.filter((k) => k !== key) : [...sectionKeys, key],
    );
  }

  const needsScopeStep = selectedLabs.some((l) => !l.flat && l.sections.length > 1);

  function validate(): string | null {
    if (activeSections.length === 0) return "Select at least one laboratory section.";
    for (const { section } of activeSections) {
      const state = stateFor(section.key);
      if (section.hardwareOnly) {
        if (state.includeHardware && state.hardwareIds.length === 0) {
          return `Select at least one hardware item for ${section.name}.`;
        }
        if (!state.includeHardware) {
          return `${section.name} is equipment only — include equipment or remove the section.`;
        }
        continue;
      }
      const volume = Number(state.annualTestVolume);
      if (!state.annualTestVolume || Number.isNaN(volume) || volume <= 0) {
        return `Enter the expected tests per year for ${section.name}.`;
      }
      if (state.parameterIds.length === 0) {
        return `Select at least one test before continuing with ${section.name}.`;
      }
      if (state.includeHardware && state.hardwareIds.length === 0) {
        return `Select at least one equipment item for ${section.name}, or set equipment to No.`;
      }
    }
    return null;
  }

  function buildSelection(): CalculatorSelection {
    return {
      labs: selectedLabs
        .map((lab) => {
          const sections: SelectionSection[] = lab.sections
            .filter((s) => sectionKeys.includes(s.key))
            .map((s) => {
              const state = stateFor(s.key);
              return {
                subLabId: s.subLabId,
                annualTestVolume: Number(state.annualTestVolume) || 0,
                includeHardware: state.includeHardware,
                selectedHardwareIds: state.includeHardware
                  ? Array.from(new Set(state.hardwareIds))
                  : [],
                selectedParameterIds: Array.from(new Set(state.parameterIds)),
              };
            });
          return { labId: lab.labId, labType: lab.labType, sections };
        })
        .filter((l) => l.sections.length > 0),
    };
  }

  // Authoritative calculation happens server-side only after the lead validates.
  const mutation = useMutation({
    mutationFn: () =>
      calculate({
        data: {
          selection: buildSelection(),
          lead: normalizeLeadInput(lead),
          mode: prefill === "ai" ? "ai" : "manual",
        },
      }),
    onSuccess: (result) => {
      if (!result.ok) {
        if (result.errors) setLeadErrors(result.errors);
        if (result.message) toast.error(result.message);
        return;
      }
      setLeadErrors({});
      setEstimate(result.estimate);
      setStep("result");
    },
    onError: (error: Error) => toast.error(error.message),
  });

  function goToLeadStep() {
    const error = validate();
    if (error) {
      toast.error(error);
      setStep("configure");
      return;
    }
    setStep("lead");
  }

  function submitLead() {
    if (mutation.isPending) return;
    const nextErrors = validateLead(normalizeLeadInput(lead));
    setLeadErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;
    mutation.mutate();
  }

  /* ------------------------------------------------------------- progress */

  const steps = useMemo(
    () =>
      [
        { id: "labs", label: "Laboratory" },
        ...(needsScopeStep ? [{ id: "scope", label: "Configuration" }] : []),
        { id: "configure", label: "Tests & Equipment" },
        { id: "review", label: "Review" },
        { id: "lead", label: "Your details" },
        { id: "result", label: "Estimate" },
      ] as { id: string; label: string }[],
    [needsScopeStep],
  );

  const completedIds = STEP_ORDER.slice(0, STEP_ORDER.indexOf(step)).map(String);

  if (catalogQuery.isLoading) {
    return (
      <PublicPage>
        <Shell>
          <div className="py-24">
            <SectionLabel>Preparing</SectionLabel>
            <p className="mt-6 font-display text-2xl text-white">
              Loading your configuration workspace…
            </p>
            <div className="mt-10 h-px w-full overflow-hidden bg-white/15">
              <div className="oh-scan h-px w-1/3 bg-white/70" />
            </div>
          </div>
        </Shell>
      </PublicPage>
    );
  }

  const isResult = step === "result";

  return (
    <PublicPage>
      <main>
        <Shell className="pt-8 pb-16">
          <SectionLabel>{isResult ? "Your estimate" : "Manual configuration"}</SectionLabel>
          <DisplayHeading className="mt-4 max-w-[18ch] text-[clamp(1.9rem,4.5vw,3.25rem)]">
            {isResult ? "Your estimated laboratory investment" : "Configure your laboratory"}
          </DisplayHeading>

          {!isResult && (
            <Panel as="div" className="mt-8">
              <ProgressIndicator
                steps={steps}
                currentId={step}
                completedIds={completedIds}
                onSelect={(id) => setStep(id as Step)}
              />
            </Panel>
          )}

          {/* ---------------------------------------------------------- labs */}
          {step === "labs" && (
            <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
              <Panel as="div" className="h-fit p-6">
                <SectionLabel>Step 01</SectionLabel>
                <SubHeading className="mt-3 text-[1.375rem]">Select laboratories</SubHeading>
                <p className="mt-4 text-sm leading-relaxed text-white/55">
                  Choose one, several, or all laboratory types you intend to operate.
                </p>
              </Panel>
              <div>
                <Panel as="div">
                  {(catalog?.labs ?? []).map((lab, index) => (
                    <SelectionRow
                      key={lab.labId}
                      index={index}
                      title={lab.labName}
                      selected={labIds.includes(lab.labId)}
                      onToggle={() => toggleLab(lab)}
                    />
                  ))}
                </Panel>
                <div className="mt-6 flex justify-end">
                  <PrimaryButton
                    disabled={labIds.length === 0}
                    onClick={() => setStep(needsScopeStep ? "scope" : "configure")}
                  >
                    Continue
                    <Arrow className={ARROW} />
                  </PrimaryButton>
                </div>
              </div>
            </div>
          )}

          {/* --------------------------------------------------------- scope */}
          {step === "scope" && (
            <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
              <Panel as="div" className="h-fit p-6">
                <SectionLabel>Step 02</SectionLabel>
                <SubHeading className="mt-3 text-[1.375rem]">Select sections</SubHeading>
                <p className="mt-4 text-sm leading-relaxed text-white/55">
                  Choose the sub-laboratories you require within each laboratory.
                </p>
              </Panel>
              <div className="space-y-6">
                {selectedLabs
                  .filter((lab) => !lab.flat && lab.sections.length > 1)
                  .map((lab) => (
                    <Panel as="div" key={lab.labId}>
                      <PanelHeader title={lab.labName} />
                      {lab.sections.map((section, index) => (
                        <SelectionRow
                          key={section.key}
                          index={index}
                          title={section.name}
                          {...(section.hardwareOnly ? { description: "Equipment only" } : {})}
                          selected={sectionKeys.includes(section.key)}
                          onToggle={() => toggleSection(section.key)}
                        />
                      ))}
                    </Panel>
                  ))}
                <div className="flex flex-wrap justify-between gap-4">
                  <SecondaryButton onClick={() => setStep("labs")}>Back</SecondaryButton>
                  <PrimaryButton
                    disabled={activeSections.length === 0}
                    onClick={() => setStep("configure")}
                  >
                    Continue
                    <Arrow className={ARROW} />
                  </PrimaryButton>
                </div>
              </div>
            </div>
          )}

          {/* ----------------------------------------------------- configure */}
          {step === "configure" && (
            <div className="mt-6 space-y-6">
              {activeSections.map(({ lab, section }, index) => (
                <Panel as="div" key={section.key}>
                  <PanelHeader
                    label={`${String(index + 1).padStart(2, "0")} · ${lab.labName}`}
                    title={section.name}
                    {...(section.hardwareOnly ? { description: "Equipment only" } : {})}
                  />
                  <div className="p-5 sm:p-8">
                    <SectionConfigurator
                      section={section}
                      state={stateFor(section.key)}
                      onChange={(next) =>
                        setSectionState((prev) => ({ ...prev, [section.key]: next }))
                      }
                    />
                  </div>
                </Panel>
              ))}

              <div className="flex flex-wrap justify-between gap-4">
                <SecondaryButton onClick={() => setStep(needsScopeStep ? "scope" : "labs")}>
                  Back
                </SecondaryButton>
                <PrimaryButton
                  onClick={() => {
                    const error = validate();
                    if (error) {
                      toast.error(error);
                      return;
                    }
                    setStep("review");
                  }}
                >
                  Review configuration
                  <Arrow className={ARROW} />
                </PrimaryButton>
              </div>
            </div>
          )}

          {/* -------------------------------------------------------- review */}
          {step === "review" && (
            <div className="mt-6 space-y-6">
              <Panel as="div" className="p-6 sm:p-8">
                <SubHeading as="h2">Your laboratory configuration</SubHeading>
                <p className="mt-4 max-w-md text-sm leading-relaxed text-white/55">
                  Check your selections below. Nothing is calculated until you confirm.
                </p>
              </Panel>

              {selectedLabs.map((lab, labIndex) => {
                const sections = lab.sections.filter((s) => sectionKeys.includes(s.key));
                if (sections.length === 0) return null;
                return (
                  <Panel as="div" key={lab.labId}>
                    <PanelHeader
                      label={String(labIndex + 1).padStart(2, "0")}
                      title={lab.labName}
                    />
                    <div className="grid gap-8 p-5 sm:p-8 lg:grid-cols-2">
                      {sections.map((section) => {
                        const state = stateFor(section.key);
                        const hardwareCount = state.includeHardware
                          ? section.hardware.filter((h) => state.hardwareIds.includes(h.id)).length
                          : 0;
                        const parameterCount = section.parameters.filter((p) =>
                          state.parameterIds.includes(p.id),
                        ).length;
                        return (
                          <div key={section.key}>
                            <p className="border-b border-white/25 pb-3 text-[0.8125rem] font-medium tracking-normal text-white">
                              {section.name}
                            </p>
                            <dl className="mt-2">
                              {!section.hardwareOnly && (
                                <ReviewRow
                                  label="Annual volume"
                                  value={Number(state.annualTestVolume || 0).toLocaleString()}
                                />
                              )}
                              {!section.hardwareOnly && (
                                <ReviewRow label="Tests selected" value={parameterCount} />
                              )}
                              {section.supportsHardware && (
                                <ReviewRow label="Equipment selected" value={hardwareCount} />
                              )}
                            </dl>
                            <ul className="mt-3 space-y-1 text-sm text-muted-foreground">
                              {section.parameters.filter(p => state.parameterIds.includes(p.id)).map(p => <li key={p.id}>{p.name}</li>)}
                              {state.includeHardware && section.hardware.filter(h => state.hardwareIds.includes(h.id)).map(h => <li key={h.id}>{h.name}</li>)}
                            </ul>
                            <div className="mt-4">
                              <ArrowAction onClick={() => setStep("configure")}>Edit</ArrowAction>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </Panel>
                );
              })}

              <div className="flex flex-wrap items-center justify-between gap-4">
                <SecondaryButton onClick={() => setStep("configure")}>
                  Back and edit
                </SecondaryButton>
                <PrimaryButton onClick={goToLeadStep}>
                  Calculate
                  <Arrow className={ARROW} />
                </PrimaryButton>
              </div>
            </div>
          )}

          {/* ---------------------------------------------------------- lead */}
          {step === "lead" && (
            <div className="mt-6 space-y-6">
              <LeadForm
                values={lead}
                errors={leadErrors}
                pending={mutation.isPending}
                onChange={setLead}
                onSubmit={submitLead}
                onBack={() => setStep("review")}
              />

              {mutation.isPending && (
                <Panel as="div" className="p-6 sm:p-8">
                  <SectionLabel>Please wait</SectionLabel>
                  <p className="mt-4 font-display text-[clamp(1.5rem,3.5vw,2.25rem)] leading-[1.05] text-white">
                    Calculating your laboratory estimate
                  </p>
                  <div className="mt-8 h-px w-full overflow-hidden bg-white/15">
                    <div className="oh-scan h-px w-1/3 bg-white/70" />
                  </div>
                </Panel>
              )}
            </div>
          )}

          {/* -------------------------------------------------------- result */}
          {step === "result" && estimate && (
            <div className="mt-8 space-y-6">
              <Panel as="div" className="p-6 sm:p-10">
                <SectionLabel>{estimate.currency}</SectionLabel>
                <p className="mt-4 font-display text-[clamp(2rem,7vw,5rem)] leading-[0.95] tracking-[-0.03em] tabular-nums text-white">
                  {money(estimate.lowerRange, estimate.currency).replace(
                    `${estimate.currency} `,
                    "",
                  )}
                  <span className="px-3 text-white/40">—</span>
                  {money(estimate.upperRange, estimate.currency).replace(
                    `${estimate.currency} `,
                    "",
                  )}
                </p>
                <p className="mt-6 max-w-xl text-sm leading-relaxed text-white/60">
                  This is an estimated range for planning purposes, based on the configuration you
                  provided. Your laboratory configuration has been prepared; a detailed proposal
                  follows separately.
                </p>
              </Panel>

              <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                {estimate.summary.map((lab) => (
                  <Panel as="div" key={lab.labName} className="p-6">
                    <p className="font-display text-xl tracking-[-0.01em] text-white">
                      {lab.labName}
                    </p>
                    <div className="mt-5 space-y-5">
                      {lab.sections.map((section) => (
                        <div key={section.name}>
                          <p className="text-[0.8125rem] font-medium tracking-normal text-white/70">
                            {section.name}
                          </p>
                          <dl className="mt-2 space-y-1 text-sm text-white/80">
                            <div className="flex justify-between gap-4">
                              <dt>Annual volume</dt>
                              <dd className="tabular-nums">
                                {section.annualTestVolume.toLocaleString()}
                              </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                              <dt>Tests selected</dt>
                              <dd className="tabular-nums">{section.parameterCount}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                              <dt>Equipment selected</dt>
                              <dd className="tabular-nums">{section.hardwareCount}</dd>
                            </div>
                          </dl>
                        </div>
                      ))}
                    </div>
                  </Panel>
                ))}
              </div>

              <div>
                <SecondaryButton onClick={() => setStep("configure")}>
                  Adjust configuration
                  <Arrow className={ARROW} />
                </SecondaryButton>
              </div>
            </div>
          )}
        </Shell>
      </main>
    </PublicPage>
  );
}

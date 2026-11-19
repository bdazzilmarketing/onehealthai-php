// Client-safe types shared by the public Manual Calculator, the AI mode (later)
// and the deterministic calculation engine. No pricing fields ever appear here
// on the public metadata side.
import { z } from "zod";

import type { WorkbookType } from "./workbook-types";

// ------------------------------------------------------------ safe metadata

export interface PublicParameter {
  id: string;
  name: string;
  groupId: string | null;
  notes: string | null;
}

export interface PublicHardware {
  id: string;
  name: string;
  groupId: string | null;
  categoryId: string | null;
}

export interface PublicGroup {
  id: string;
  name: string;
  parameterIds: string[];
}

/** A sub-laboratory, or the lab itself for flat laboratories. */
export interface PublicSection {
  /** Stable key for UI state: the sub-lab id, or `lab:<labId>` for flat labs. */
  key: string;
  subLabId: string | null;
  name: string;
  /** Hardware only (e.g. Histopathology): no parameters, no volume. */
  hardwareOnly: boolean;
  supportsHardware: boolean;
  groups: PublicGroup[];
  ungroupedParameterIds: string[];
  parameters: PublicParameter[];
  hardware: PublicHardware[];
  categories: { id: string; name: string }[];
}

export interface PublicLab {
  labId: string;
  labType: WorkbookType;
  labName: string;
  datasetVersionId: string;
  datasetVersionNumber: number;
  flat: boolean;
  sections: PublicSection[];
}

export interface PublicCatalog {
  labs: PublicLab[];
  currency: string;
}

// -------------------------------------------------------- selection schema

export const selectionSectionSchema = z.object({
  subLabId: z.string().guid().nullable(),
  annualTestVolume: z.number().finite().min(0).max(1_000_000_000).default(0),
  includeHardware: z.boolean().default(false),
  selectedHardwareIds: z.array(z.string().guid()).default([]),
  selectedParameterIds: z.array(z.string().guid()).default([]),
});

export const selectionLabSchema = z.object({
  labId: z.string().guid(),
  labType: z.enum(["core_lab", "poct", "molecular", "microbiology", "genetics"]),
  sections: z.array(selectionSectionSchema).min(1),
});

export const calculatorSelectionSchema = z.object({
  labs: z.array(selectionLabSchema).min(1),
});

export type SelectionSection = z.infer<typeof selectionSectionSchema>;
export type SelectionLab = z.infer<typeof selectionLabSchema>;
export type CalculatorSelection = z.infer<typeof calculatorSelectionSchema>;

// --------------------------------------------------------------- results

export const CALCULATION_VERSION = "equal_distribution_v1" as const;

export interface InternalParameterLine {
  parameterId: string;
  parameterName: string;
  pricePerTest: number;
  allocatedAnnualVolume: number;
  annualCalculatedCost: number;
}

export interface InternalHardwareLine {
  hardwareId: string;
  hardwareName: string;
  price: number;
}

export interface InternalSection {
  subLabId: string | null;
  subLabName: string;
  annualTestVolume: number;
  selectedParameterCount: number;
  allocatedVolumePerParameter: number;
  parameters: InternalParameterLine[];
  hardware: InternalHardwareLine[];
  parameterSubtotal: number;
  hardwareSubtotal: number;
  subtotal: number;
}

export interface InternalLab {
  labId: string;
  labType: WorkbookType;
  labName: string;
  datasetVersionId: string;
  datasetVersionNumber: number;
  subLabs: InternalSection[];
  parameterSubtotal: number;
  hardwareSubtotal: number;
  subtotal: number;
}

export interface InternalCalculation {
  calculationId: string;
  calculationVersion: string;
  calculatedAt: string;
  currency: string;
  datasetVersions: Record<string, number>;
  labs: InternalLab[];
  totalParameterCost: number;
  totalHardwareCost: number;
  internalTotal: number;
}

/** The only calculation data the browser is ever given. */
export interface PublicEstimate {
  calculationId: string;
  calculationVersion: string;
  calculatedAt: string;
  currency: string;
  lowerRange: number;
  upperRange: number;
  datasetVersions: Record<string, number>;
  summary: {
    labName: string;
    sections: {
      name: string;
      annualTestVolume: number;
      parameterCount: number;
      hardwareCount: number;
    }[];
  }[];
}

export class CalculationError extends Error {}

/** Internal detail about one item whose published price is missing/invalid. */
export interface PricingIssue {
  kind: "parameter" | "hardware";
  itemId: string;
  itemName: string;
  labId: string;
  labType: WorkbookType;
  datasetVersionId: string;
  datasetVersionNumber: number;
  reason: "missing" | "null" | "non_numeric" | "negative";
  rawValue: string;
}

export const PRICING_UNAVAILABLE_MESSAGE =
  "We're unable to calculate this configuration because some pricing information is currently unavailable. Please try again later.";

/**
 * Defensive control: a selected item has no valid published price.
 * The public message never contains pricing or database detail; `issues`
 * stays server-side for troubleshooting.
 */
export class PricingUnavailableError extends CalculationError {
  readonly issues: PricingIssue[];

  constructor(issues: PricingIssue[]) {
    super(PRICING_UNAVAILABLE_MESSAGE);
    this.name = "PricingUnavailableError";
    this.issues = issues;
  }
}

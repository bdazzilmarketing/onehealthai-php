// Client-safe types and helpers shared by the importer, server functions and UI.

export type WorkbookType =
  | "core_lab"
  | "poct"
  | "molecular"
  | "microbiology"
  | "genetics";

export const WORKBOOK_TYPES: { value: WorkbookType; label: string }[] = [
  { value: "core_lab", label: "Core Lab" },
  { value: "poct", label: "Point of Care Testing (POCT)" },
  { value: "molecular", label: "Molecular Lab" },
  { value: "microbiology", label: "Microbiology Lab" },
  { value: "genetics", label: "Genetics Lab" },
];

export const LAB_META: Record<
  WorkbookType,
  { code: string; name: string; order: number }
> = {
  core_lab: { code: "core_lab", name: "Core Lab", order: 1 },
  poct: { code: "poct", name: "Point of Care Testing", order: 2 },
  molecular: { code: "molecular", name: "Molecular Lab", order: 3 },
  microbiology: { code: "microbiology", name: "Microbiology Lab", order: 4 },
  genetics: { code: "genetics", name: "Genetics Lab", order: 5 },
};

export type DatasetStatus =
  | "uploaded"
  | "validating"
  | "validation_failed"
  | "validated"
  | "published"
  | "archived";

export type IssueSeverity = "critical" | "warning";

export interface ValidationIssue {
  severity: IssueSeverity;
  sheet: string;
  row?: number | null;
  field?: string | null;
  message: string;
  suggestion?: string | null;
}

export interface ValidationSummary {
  laboratories: number;
  subLabs: number;
  parameterGroups: number;
  parameters: number;
  hardware: number;
  hardwareCategories: number;
  criticalErrors: number;
  warnings: number;
}

export interface ParsedLab {
  code: string;
  name: string;
  order: number;
}
export interface ParsedSubLab {
  labCode: string;
  code: string;
  name: string;
  sourceSheet: string;
  order: number;
}
export interface ParsedGroup {
  labCode: string;
  subLabCode: string | null;
  groupName: string;
  normalized: string;
  order: number;
}
export interface ParsedCategory {
  labCode: string;
  subLabCode: string | null;
  categoryName: string;
  normalized: string;
  order: number;
}
export interface ParsedHardware {
  labCode: string;
  subLabCode: string | null;
  hardwareName: string;
  groupNormalized: string | null;
  categoryNormalized: string | null;
  price: number | null;
  sourceSheet: string;
  sourceRow: number;
  order: number;
}
export interface ParsedParameter {
  labCode: string;
  subLabCode: string | null;
  groupNormalized: string | null;
  parameterName: string;
  pricePerTest: number | null;
  optionalNotes: string | null;
  sourceSheet: string;
  sourceRow: number;
  order: number;
}

export interface ParsedWorkbook {
  workbookType: WorkbookType;
  laboratories: ParsedLab[];
  subLabs: ParsedSubLab[];
  parameterGroups: ParsedGroup[];
  hardwareCategories: ParsedCategory[];
  hardware: ParsedHardware[];
  parameters: ParsedParameter[];
  issues: ValidationIssue[];
  summary: ValidationSummary;
}

/** Normalized matching value: trimmed, collapsed whitespace, lower-cased. */
export function normalizeKey(value: string): string {
  return value.trim().replace(/\s+/g, " ").toLowerCase();
}

export function slugCode(value: string): string {
  return normalizeKey(value)
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
}

/**
 * Sheet name -> normalized sub-lab display label.
 * The source Core Lab workbook misspells "Coagulation" as "Cogulation";
 * both spellings map to the correct public label.
 */
export const SUB_LAB_LABEL_OVERRIDES: Record<string, string> = {
  cogulation: "Coagulation",
  coagulation: "Coagulation",
  chemistry: "Chemistry",
  immunology: "Immunology",
  histopathology: "Histopathology",
  "histopathology hardware": "Histopathology",
  "blood gas portable poc": "Blood Gas — Portable (POC)",
  "blood gas benchtop poc": "Blood Gas — Benchtop (POC)",
  "urine analyzer - poc": "Urine Analyzer (POC)",
  "hba1c analyzer - poc": "HbA1c Analyzer (POC)",
  "glucometer - poc": "Glucometer (POC)",
  "pcr- respiratory tests - poc": "PCR — Respiratory Tests (POC)",
  "rapid diagostic test kits": "Rapid Diagnostic Test Kits",
  "rapid diagnostic test kits": "Rapid Diagnostic Test Kits",
};

export function subLabLabel(sheetName: string): string {
  return SUB_LAB_LABEL_OVERRIDES[normalizeKey(sheetName)] ?? sheetName.trim();
}

export function formatMoney(value: number | null | undefined, currency = "AED") {
  if (value === null || value === undefined) return "—";
  return `${currency} ${Number(value).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

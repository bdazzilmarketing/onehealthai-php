// Client-safe Stage 3 types. Nothing here is or can become a monetary value.
import type { CalculatorSelection } from "./calculator-types";

export interface AiChatMessage {
  role: "user" | "assistant";
  content: string;
}

export interface AiStateSection {
  sectionName: string;
  hardwareOnly: boolean;
  annualTestVolume: number;
  includeHardware: boolean;
  hardwareNames: string[];
  parameterNames: string[];
}

export interface AiStateLab {
  labName: string;
  sections: AiStateSection[];
}

export interface AiWorkingState {
  labs: AiStateLab[];
  missingFields: string[];
  conversationStatus: "collecting" | "ready";
}

export interface AiTurnResult {
  ok: true;
  sessionId: string;
  reply: string;
  state: AiWorkingState;
  /** Clickable next step derived from validated state (catalog names/IDs only). */
  picker?: AiPicker | null;
}

// ---- clickable choices (no model call; applied deterministically server-side)

export type AiPickerStep = "lab" | "section" | "hardware" | "tests" | "volume" | "done";

export interface AiPickerOption {
  id: string;
  label: string;
  /** Short catalog fact, e.g. "4 sections" or a hardware category. */
  hint?: string | null;
  /** Grouping heading for checklists (parameter group or hardware category). */
  group?: string | null;
  /** Tests only: belongs to a parameter group of the chosen equipment. */
  matchesHardware?: boolean;
  /** Already part of the saved configuration. */
  selected?: boolean;
}

export interface AiPicker {
  step: AiPickerStep;
  prompt: string;
  labId: string | null;
  subLabId: string | null;
  multi: boolean;
  options: AiPickerOption[];
  /** Tests only: names of the chosen equipment used to narrow the list. */
  hardwareNames: string[];
  /** Volume only: the saved annual volume, if any. */
  currentVolume: number | null;
  /** Done only: what the visitor can do next. */
  canAddSection: boolean;
  canReview: boolean;
  /** Section step of a planned laboratory: the visitor may drop it from the plan. */
  canSkipLab: boolean;
}

export type AiChoice =
  | { step: "lab"; labId: string }
  | { step: "labs"; labIds: string[] }
  | { step: "section"; labId: string; subLabId: string | null }
  | { step: "sections"; labId: string; subLabIds: (string | null)[] }
  | { step: "skip_lab"; labId: string }
  | { step: "hardware"; labId: string; subLabId: string | null; ids: string[] }
  | { step: "tests"; labId: string; subLabId: string | null; ids: string[] }
  | { step: "volume"; labId: string; subLabId: string | null; volume: number }
  | { step: "add_section"; labId: string }
  | { step: "add_lab" };

export interface AiChoiceResult {
  ok: true;
  sessionId: string;
  /** What the visitor picked, rendered from catalog names. */
  echo: string;
  reply: string;
  state: AiWorkingState;
  picker: AiPicker | null;
  /** Laboratories the visitor chose, in the order they are set up. */
  plan: string[];
}

export interface AiTurnError {
  ok: false;
  code: "unavailable" | "not_configured" | "rate_limited" | "invalid" | "session";
  message: string;
  retryAfterSeconds?: number | null;
}

export interface AiAvailability {
  available: boolean;
  reason: string | null;
}

export interface AiHandoff {
  ok: boolean;
  message?: string;
  selection?: CalculatorSelection;
}

export const AI_PREFILL_STORAGE_KEY = "onehealth.ai.prefill";

export const AI_UNAVAILABLE_MESSAGE =
  "The AI assistant is temporarily unavailable. You can try again or continue using Manual Mode.";

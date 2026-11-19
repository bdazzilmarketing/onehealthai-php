// Client-safe lead/session types and shared validation.
// Contains contact-field logic only — never pricing and never AI payload data.

export type CalculatorMode = "ai" | "manual";

export interface LeadInput {
  fullName: string;
  company: string;
  phone: string;
  email: string;
}

/** The only lead data ever returned to the browser (own session only). */
export interface LeadSessionPublic {
  sessionId: string;
  fullName: string;
  company: string;
  phone: string;
  email: string;
  selectedMode: CalculatorMode;
}

export type LeadFieldErrors = Partial<Record<keyof LeadInput, string>>;

export const LEAD_FIELD_LIMITS = {
  fullName: 120,
  company: 160,
  email: 254,
  phone: 32,
} as const;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;
const PHONE_ALLOWED = /^[+()\-.\s0-9]+$/;

export function normalizeLeadInput(input: Partial<LeadInput>): LeadInput {
  return {
    fullName: String(input.fullName ?? "").trim().replace(/\s+/g, " "),
    company: String(input.company ?? "").trim().replace(/\s+/g, " "),
    phone: String(input.phone ?? "").trim().replace(/\s+/g, " "),
    email: String(input.email ?? "").trim().toLowerCase(),
  };
}

/** E.164-ish digits form kept for downstream reporting. Never used for identity. */
export function normalizePhone(phone: string): string {
  const digits = phone.replace(/[^\d]/g, "");
  return phone.trim().startsWith("+") ? `+${digits}` : digits;
}

export function validateLead(input: LeadInput): LeadFieldErrors {
  const errors: LeadFieldErrors = {};

  if (input.fullName.length < 2) errors.fullName = "Please enter your full name.";
  else if (input.fullName.length > LEAD_FIELD_LIMITS.fullName)
    errors.fullName = "That name is too long.";

  if (input.company.length < 2) errors.company = "Please enter your company name.";
  else if (input.company.length > LEAD_FIELD_LIMITS.company)
    errors.company = "That company name is too long.";

  if (input.email.length === 0) errors.email = "Please enter your email address.";
  else if (input.email.length > LEAD_FIELD_LIMITS.email)
    errors.email = "That email address is too long.";
  else if (!EMAIL_PATTERN.test(input.email))
    errors.email = "Please enter a valid email address.";

  const digits = input.phone.replace(/[^\d]/g, "");
  if (input.phone.length === 0) errors.phone = "Please enter your phone number.";
  else if (input.phone.length > LEAD_FIELD_LIMITS.phone)
    errors.phone = "That phone number is too long.";
  else if (!PHONE_ALLOWED.test(input.phone) || digits.length < 7 || digits.length > 15)
    errors.phone = "Please enter a valid phone number.";

  return errors;
}

export function isCalculatorMode(value: unknown): value is CalculatorMode {
  return value === "ai" || value === "manual";
}

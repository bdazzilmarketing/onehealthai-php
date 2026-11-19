import {
  Arrow,
  FormField,
  Panel,
  PanelHeader,
  PrimaryButton,
  SecondaryButton,
  editorialInput,
} from "@/components/public/ui";
import { LEAD_FIELD_LIMITS, type LeadFieldErrors, type LeadInput } from "@/lib/lead-types";

const ARROW =
  "transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 motion-reduce:transition-none";

const FIELDS: { key: keyof LeadInput; label: string; type: string; autoComplete: string }[] = [
  { key: "fullName", label: "Full Name", type: "text", autoComplete: "name" },
  { key: "company", label: "Company", type: "text", autoComplete: "organization" },
  { key: "phone", label: "Phone Number", type: "tel", autoComplete: "tel" },
  { key: "email", label: "Email Address", type: "email", autoComplete: "email" },
];

export const EMPTY_LEAD: LeadInput = { fullName: "", company: "", phone: "", email: "" };

interface LeadFormProps {
  values: LeadInput;
  errors: LeadFieldErrors;
  pending: boolean;
  onChange: (next: LeadInput) => void;
  onSubmit: () => void;
  onBack: () => void;
}

/**
 * Final gate before the estimate. Purely presentational: the parent owns the
 * lead values (so they survive Back navigation) and the server owns validation.
 */
export function LeadForm({ values, errors, pending, onChange, onSubmit, onBack }: LeadFormProps) {
  return (
    <Panel as="div">
      <PanelHeader
        title="Your Estimate Is Ready"
        description="Enter your details to view your laboratory investment estimate."
      />
      <form
        className="p-6 sm:p-8"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          if (!pending) onSubmit();
        }}
      >
        <div className="grid gap-8 sm:grid-cols-2">
          {FIELDS.map((field) => (
            <FormField key={field.key} id={field.key} label={field.label} error={errors[field.key]}>
              <input
                id={field.key}
                type={field.type}
                autoComplete={field.autoComplete}
                maxLength={LEAD_FIELD_LIMITS[field.key]}
                value={values[field.key]}
                disabled={pending}
                onChange={(e) => onChange({ ...values, [field.key]: e.target.value })}
                aria-invalid={Boolean(errors[field.key])}
                aria-describedby={errors[field.key] ? `${field.key}-error` : undefined}
                className={editorialInput}
              />
            </FormField>
          ))}
        </div>

        <div className="mt-10 flex flex-wrap items-center justify-between gap-4 border-t border-white/12 pt-8">
          <SecondaryButton type="button" onClick={onBack} disabled={pending}>
            Back to review
          </SecondaryButton>
          <PrimaryButton type="submit" disabled={pending}>
            {pending ? "Calculating…" : "View my estimate"}
            <Arrow className={ARROW} />
          </PrimaryButton>
        </div>
      </form>
    </Panel>
  );
}

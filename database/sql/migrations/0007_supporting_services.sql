-- Email delivery ledger for idempotent sends. Lovable's email API deduplicated
-- retries by idempotency key on its side; SMTP and Microsoft Graph do not, so
-- the application records each keyed send here before and after delivery.
-- Holds no message content: the key, the template name and a hash of the
-- recipient address only.
CREATE TABLE public.email_deliveries (
  idempotency_key text PRIMARY KEY,
  template text NOT NULL,
  recipient_hash text NOT NULL,
  status text NOT NULL CHECK (status IN ('sending', 'sent', 'failed')),
  attempts integer NOT NULL DEFAULT 1,
  claimed_at timestamptz NOT NULL DEFAULT now(),
  sent_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE public.email_deliveries ENABLE ROW LEVEL SECURITY;
-- Server-side only (service role bypasses RLS); no staff policy.
GRANT SELECT, INSERT, UPDATE, DELETE ON public.email_deliveries TO onehealth_service;

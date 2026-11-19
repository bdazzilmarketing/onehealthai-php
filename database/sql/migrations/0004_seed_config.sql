-- Singleton configuration every environment needs to start. No production
-- data: catalogs, prompts, users, recipients and history arrive through the
-- approved data import.

-- Finding 4301 quota limits as last verified in production (migration spec
-- section 6.1). The historical migrations' column defaults are looser
-- (network 15/120/400, no rolling window, no visitor spacing), so the row is
-- seeded explicitly rather than from defaults.
INSERT INTO public.ai_quota_settings (
  id, enabled,
  visitor_per_minute, visitor_per_hour, visitor_per_day,
  ip_per_minute, ip_per_hour, ip_per_day,
  ip_rolling_limit, ip_rolling_window_seconds, visitor_min_interval_seconds,
  concurrent_per_visitor, concurrent_per_ip,
  global_requests_per_day, global_tokens_per_day,
  alert_threshold_1, alert_threshold_2,
  max_input_chars, max_output_tokens, max_turns
) VALUES (
  true, true,
  6, 40, 120,
  6, 40, 120,
  20, 120, 3,
  1, 4,
  2000, 3000000,
  75, 90,
  2000, 4000, 40
) ON CONFLICT (id) DO NOTHING;

-- Same rows the historical migrations insert.
INSERT INTO public.settings (key, value, description) VALUES
  ('calculator_variance', '{"lower_percent": 20, "upper_percent": 20, "currency": "AED"}'::jsonb,
   'Public estimate variance range applied in Stage 2'),
  ('ai_model', '{"model": "gpt-4o-mini", "temperature": 0.2, "max_tokens": 2048}'::jsonb,
   'OpenAI model configuration for future AI mode'),
  ('public_estimate_lower_variance', '-20', 'Lower variance percentage applied to public estimates (negative number)'),
  ('public_estimate_upper_variance', '20', 'Upper variance percentage applied to public estimates')
ON CONFLICT (key) DO NOTHING;

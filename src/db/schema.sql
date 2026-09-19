-- ============================================================================
-- InvoicePro NG — Database Schema (V1 / MVP)
-- Run with: npm run db:create-schema
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS "pgcrypto"; -- gives us gen_random_uuid()

-- ---------------------------------------------------------------------------
-- ENUM TYPES
-- ---------------------------------------------------------------------------
DO $$ BEGIN CREATE TYPE plan_type AS ENUM ('free', 'pro'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN CREATE TYPE invoice_status AS ENUM ('draft', 'unpaid', 'paid', 'overdue', 'cancelled'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN CREATE TYPE payment_status AS ENUM ('pending', 'success', 'failed'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN CREATE TYPE payment_method AS ENUM ('bank_transfer', 'card', 'ussd', 'other'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN CREATE TYPE recurring_frequency AS ENUM ('weekly', 'monthly', 'quarterly', 'yearly'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN CREATE TYPE recurring_status AS ENUM ('active', 'paused', 'cancelled'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN CREATE TYPE tax_type AS ENUM ('VAT', 'GST', 'OTHER'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN CREATE TYPE customer_source AS ENUM ('manual', 'contacts'); EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ---------------------------------------------------------------------------
-- USERS  (auth + account)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name            VARCHAR(150) NOT NULL,
  business_name        VARCHAR(150) NOT NULL,
  email                VARCHAR(255) NOT NULL UNIQUE,
  password_hash        TEXT NOT NULL,
  is_email_verified    BOOLEAN NOT NULL DEFAULT FALSE,
  totp_secret          TEXT,
  totp_enabled         BOOLEAN NOT NULL DEFAULT FALSE,
  plan                 plan_type NOT NULL DEFAULT 'free',
  plan_renews_at       TIMESTAMPTZ,
  onboarding_completed_at TIMESTAMPTZ,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- BUSINESS PROFILE  (Profile Setup step)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_profiles (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id          UUID NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  contact_phone    VARCHAR(30),
  contact_address  TEXT,
  logo_url         TEXT,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- 2FA BACKUP CODES  (TOTP recovery — see Risk & Mitigation)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS two_factor_backup_codes (
  id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  code_hash  TEXT NOT NULL,
  used_at    TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_2fa_backup_user_id ON two_factor_backup_codes(user_id);

-- ---------------------------------------------------------------------------
-- REFRESH TOKENS  (JWT session management)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refresh_tokens (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash  TEXT NOT NULL,
  expires_at  TIMESTAMPTZ NOT NULL,
  revoked_at  TIMESTAMPTZ,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user_id ON refresh_tokens(user_id);

-- ---------------------------------------------------------------------------
-- CUSTOMERS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name       VARCHAR(150) NOT NULL,
  email      VARCHAR(255),
  phone      VARCHAR(30),
  address    TEXT,
  source     customer_source NOT NULL DEFAULT 'manual',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_customers_user_id ON customers(user_id);
CREATE INDEX IF NOT EXISTS idx_customers_name_trgm ON customers USING btree (user_id, lower(name));

-- ---------------------------------------------------------------------------
-- TAX RATES  (VAT / GST configuration)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_rates (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id      UUID REFERENCES users(id) ON DELETE CASCADE,
  name         VARCHAR(50) NOT NULL,
  type         tax_type NOT NULL DEFAULT 'VAT',
  rate_percent NUMERIC(5,2) NOT NULL CHECK (rate_percent >= 0 AND rate_percent <= 100),
  is_default   BOOLEAN NOT NULL DEFAULT FALSE,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_tax_rates_user_id ON tax_rates(user_id);

-- ---------------------------------------------------------------------------
-- RECURRING SCHEDULES
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recurring_schedules (
  id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id              UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  customer_id          UUID NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  frequency            recurring_frequency NOT NULL,
  next_run_date        DATE NOT NULL,
  status               recurring_status NOT NULL DEFAULT 'active',
  line_items_template  JSONB NOT NULL, -- [{ description, quantity, unit_price }]
  notes                TEXT,
  payment_terms        TEXT,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_recurring_user_id ON recurring_schedules(user_id);
CREATE INDEX IF NOT EXISTS idx_recurring_due ON recurring_schedules(next_run_date) WHERE status = 'active';

-- ---------------------------------------------------------------------------
-- INVOICES
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id                     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id                UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  customer_id            UUID NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
  recurring_schedule_id  UUID REFERENCES recurring_schedules(id) ON DELETE SET NULL,
  invoice_number         VARCHAR(30) NOT NULL,
  status                 invoice_status NOT NULL DEFAULT 'unpaid',
  currency               VARCHAR(3) NOT NULL DEFAULT 'NGN',
  subtotal               NUMERIC(14,2) NOT NULL DEFAULT 0,
  tax_amount             NUMERIC(14,2) NOT NULL DEFAULT 0,
  total                  NUMERIC(14,2) NOT NULL DEFAULT 0,
  amount_paid            NUMERIC(14,2) NOT NULL DEFAULT 0,
  notes                  TEXT,
  payment_terms          TEXT,
  issue_date             DATE NOT NULL DEFAULT CURRENT_DATE,
  due_date               DATE NOT NULL,
  pdf_url                TEXT,
  sent_at                TIMESTAMPTZ,
  paid_at                TIMESTAMPTZ,
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (user_id, invoice_number)
);
CREATE INDEX IF NOT EXISTS idx_invoices_user_id ON invoices(user_id);
CREATE INDEX IF NOT EXISTS idx_invoices_customer_id ON invoices(customer_id);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status);
CREATE INDEX IF NOT EXISTS idx_invoices_due_date ON invoices(due_date) WHERE status IN ('unpaid', 'overdue');

-- ---------------------------------------------------------------------------
-- INVOICE ITEMS  (line items)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_items (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  invoice_id   UUID NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  description  VARCHAR(255) NOT NULL,
  quantity     NUMERIC(10,2) NOT NULL DEFAULT 1,
  unit_price   NUMERIC(14,2) NOT NULL,
  amount       NUMERIC(14,2) NOT NULL, -- quantity * unit_price, stored for fast reads
  position     INT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice_id ON invoice_items(invoice_id);

-- ---------------------------------------------------------------------------
-- PAYMENTS  (Payscribe)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  invoice_id            UUID NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  payscribe_reference   VARCHAR(100) UNIQUE,
  amount                NUMERIC(14,2) NOT NULL,
  method                payment_method,
  status                payment_status NOT NULL DEFAULT 'pending',
  raw_webhook_payload   JSONB,
  paid_at               TIMESTAMPTZ,
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_payments_invoice_id ON payments(invoice_id);

-- ---------------------------------------------------------------------------
-- WEBHOOK EVENTS  (idempotency + retry + audit trail)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_events (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  provider      VARCHAR(30) NOT NULL, -- 'payscribe'
  event_id      VARCHAR(150) NOT NULL UNIQUE,
  payload       JSONB NOT NULL,
  processed_at  TIMESTAMPTZ,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- EXPENSES
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id      UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  description  VARCHAR(255) NOT NULL,
  category     VARCHAR(100),
  amount       NUMERIC(14,2) NOT NULL,
  tax_rate_id  UUID REFERENCES tax_rates(id) ON DELETE SET NULL,
  incurred_at  DATE NOT NULL DEFAULT CURRENT_DATE,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_expenses_user_id ON expenses(user_id);

-- ---------------------------------------------------------------------------
-- updated_at auto-touch trigger
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
DECLARE
  t TEXT;
BEGIN
  FOREACH t IN ARRAY ARRAY['users','business_profiles','customers','invoices','recurring_schedules']
  LOOP
    EXECUTE format(
      'DROP TRIGGER IF EXISTS trg_%I_updated_at ON %I;
       CREATE TRIGGER trg_%I_updated_at BEFORE UPDATE ON %I
       FOR EACH ROW EXECUTE FUNCTION set_updated_at();',
      t, t, t, t
    );
  END LOOP;
END $$;

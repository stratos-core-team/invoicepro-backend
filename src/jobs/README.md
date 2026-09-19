# Scheduled jobs

Not wired up yet — this is where recurring, time-based work lives once the
core modules exist. Suggested jobs, matching the PRD:

- **generateDueRecurringInvoices** — finds `recurring_schedules` where
  `status = 'active'` and `next_run_date <= today`, generates a new invoice
  from `template`, logs it to `recurring_invoice_log`, advances `next_run_date`.
- **markOverdueInvoices** — flips `invoices.status` from `sent` to `overdue`
  once `due_date` has passed and no payment is recorded.
- **paymentWebhookRetrySweep** — re-attempts reconciliation for `payments`
  stuck in `pending` past a timeout window (defense-in-depth alongside
  Payscribe's own webhook retries).

Run these with `node-cron` in-process for the MVP, or move to a managed
scheduler (e.g. a cron-triggered serverless function) once traffic grows
past a single instance.

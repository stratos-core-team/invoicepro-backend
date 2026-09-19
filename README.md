# InvoicePro NG — Backend

Node/Express + PostgreSQL API for InvoicePro NG (see PRD V2.3 for full product spec).

## Stack

- **Runtime:** Node.js 18+, Express 4
- **DB:** PostgreSQL, accessed via raw SQL through `pg` (no ORM) + `node-pg-migrate` for schema migrations
- **Auth:** JWT (access + refresh tokens), bcrypt password hashing, optional TOTP 2FA (`otplib`)
- **Validation:** Zod
- **Payments:** Payscribe (webhook-driven confirmation)

## Project structure

```
src/
  config/         env.js (typed env access), db.js (pg Pool + transaction helper)
  routes/         one *.routes.js per module, aggregated in routes/index.js
  controllers/    thin HTTP layer — parse req, call service, shape response
  services/       business logic (invoice totals, recurring billing, tax calc, etc.)
  repositories/   raw SQL queries per table — the only layer that talks to `db.js`
  middlewares/    authenticate, validate, errorHandler, rate limiting
  validators/     Zod schemas per module
  jobs/           cron-style jobs (e.g. generate due recurring invoices, mark overdue)
  utils/          AppError, apiResponse, shared helpers
  app.js          Express app + middleware pipeline (no listen())
  server.js       process entry point — listen() + graceful shutdown
migrations/       node-pg-migrate migration files (numbered, one table per file)
```

This layered split (routes → controllers → services → repositories) keeps SQL
isolated in one place per table, so swapping to an ORM later — or unit-testing
business logic without a live DB — stays straightforward.

## Database schema (v1)

| Table                    | Purpose |
|---------------------------|---------|
| `users`                   | Auth + business profile + plan (free/pro) + TOTP secret |
| `backup_codes`            | 2FA recovery codes |
| `refresh_tokens`          | JWT refresh token sessions (revocable, per device) |
| `customers`               | Saved client records per user |
| `tax_rates`                | User-configurable VAT/GST rates |
| `invoices`                | Core invoice record (status, totals, due date) |
| `invoice_items`           | Line items per invoice |
| `recurring_schedules`     | Recurring billing config (frequency, template, next run) |
| `recurring_invoice_log`   | Audit trail linking generated invoices back to their schedule |
| `payments`                | Payscribe payment attempts + webhook payloads, idempotent |
| `expenses`                | Logged expenses with computed tax |

All tables use `uuid` primary keys (`gen_random_uuid()` via `pgcrypto`) and
auto-maintained `updated_at` columns via a shared trigger.

## Getting started

```bash
cp .env.example .env
# edit .env: set DATABASE_URL and JWT secrets at minimum

npm install
npm run migrate:up      # creates all tables
npm run dev             # starts the API on http://localhost:4000
```

Health check: `GET /health`
API base path: `/api/v1`

## Adding a new module (e.g. Customers)

1. `src/validators/customers.validators.js` — Zod schemas
2. `src/repositories/customers.repository.js` — raw SQL (`SELECT`/`INSERT`/...)
3. `src/services/customers.service.js` — business rules, calls the repository
4. `src/controllers/customers.controller.js` — req/res glue, calls the service
5. `src/routes/customers.routes.js` — wires up `authenticate` + `validate` + controller
6. Register it in `src/routes/index.js`

## Migrations

```bash
npm run migrate:create -- add-some-table
npm run migrate:up
npm run migrate:down     # rolls back the last migration
```

## Notes / decisions

- **No ORM** — raw SQL via `pg` for full control over query performance and
  to keep the learning curve close to plain SQL/Postgres.
- **Payscribe webhook** must be mounted with `express.raw()`, not the global
  JSON parser, so its signature can be verified against the exact raw bytes
  (see the comment in `src/routes/index.js`).
- **Recurring billing & overdue-marking** are meant to run as scheduled jobs
  (`src/jobs/`), triggered by `node-cron` or an external scheduler — not on
  every request.

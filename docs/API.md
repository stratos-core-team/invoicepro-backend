# InvoicePro NG API

## Authentication
POST `/api/auth/register.php`
POST `/api/auth/login.php`

Protected requests:
`Authorization: Bearer <token>`

## Customers
GET `/api/customers/index.php`
POST `/api/customers/index.php`

## Invoices
GET `/api/invoices/index.php`
POST `/api/invoices/index.php`

## Health
GET `/health.php`

## Payscribe webhook
POST `/webhooks/payscribe.php`

Automatic payment reconciliation is intentionally not enabled until official Payscribe webhook signature verification is implemented.

# InvoicePro NG Backend

Backend-only PHP 8+ / MySQL API for InvoicePro NG.

## Included
- Environment configuration
- MySQL connection
- JSON API helpers
- CORS middleware
- JWT authentication
- Registration/login
- Customer list/search/create
- Invoice list/create
- Multiple line items
- 14-day default due date
- Overdue display logic
- Payscribe webhook event storage foundation
- Database tables for payments, expenses, recurring invoices, subscriptions, notifications and TOTP recovery

## Setup
1. Create MySQL database `invoicepro_ng`.
2. Import `database/schema.sql`.
3. Copy `.env.example` to `.env`.
4. Update DB credentials and `JWT_SECRET`.
5. Serve via Apache/PHP.
6. Test `GET /health.php`.

## Push to GitHub
```bash
git init
git add .
git commit -m "Initial InvoicePro backend setup"
git remote add origin https://github.com/stratos-core-team/invoicepro-backend.git
git branch -M main
git push -u origin main
```

If origin exists:
```bash
git remote set-url origin https://github.com/stratos-core-team/invoicepro-backend.git
git push -u origin main
```

## Security
Never commit `.env`. Use HTTPS in production. Set a specific `CORS_ORIGIN`. Payscribe payment reconciliation must wait for verified webhook signature handling.

const { Router } = require('express');

const router = Router();

/**
 * Module routes are mounted here one at a time as they're built.
 * Each is a self-contained router file under src/routes/*.routes.js,
 * backed by a controller + service + repository of the same name.
 *
 * Example (uncomment as each module is implemented):
 *
 *   router.use('/users', require('./users.routes'));
 *   router.use('/customers', require('./customers.routes'));
 *   router.use('/invoices', require('./invoices.routes'));
 *   router.use('/payments', require('./payments.routes'));       // includes Payscribe webhook
 *   router.use('/recurring-schedules', require('./recurringSchedules.routes'));
 *   router.use('/expenses', require('./expenses.routes'));
 *   router.use('/tax-rates', require('./taxRates.routes'));
 *   router.use('/dashboard', require('./dashboard.routes'));
 *
 * IMPORTANT: The Payscribe webhook endpoint (POST /payments/webhook) must be
 * registered with express.raw({ type: 'application/json' }) instead of the
 * global express.json() parser, because signature verification needs the
 * exact raw request body bytes. Mount it directly in app.js *before*
 * app.use(express.json(...)) once the payments module exists, e.g.:
 *
 *   app.post('/api/v1/payments/webhook', express.raw({ type: 'application/json' }), paymentsController.handleWebhook);
 */

router.use('/auth', require('./auth.routes'));
router.use('/customers', require('./customers.routes'));

router.get('/', (req, res) => {
  res.json({ success: true, data: { message: 'InvoicePro NG API v1' } });
});

module.exports = router;

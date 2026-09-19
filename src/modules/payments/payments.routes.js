const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO:
// - POST /invoices/:id/checkout : create a Payscribe payment link/session for an invoice
// - POST /webhooks/payscribe : PUBLIC, no auth. Must:
//     1. Verify signature against PAYSCRIBE_WEBHOOK_SECRET
//     2. Check webhook_events.event_id for idempotency before processing
//     3. Update payments + invoices.status = 'paid' in a transaction
//     4. Always return 200 quickly; do slow work (email, PostHog event) after ack

router.post('/invoices/:invoiceId/checkout', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: create Payscribe checkout session' });
});

// Note: mounted publicly (no requireAuth) — see routes/index.js
router.post('/webhooks/payscribe', (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: handle Payscribe webhook' });
});

module.exports = router;

const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO:
// - POST / : create invoice + invoice_items in a transaction, auto-generate
//   invoice_number, default due_date = issue_date + 14 days
// - GET / : list with filters (status=paid|unpaid|overdue, search, pagination)
// - GET /:id : full invoice + items + customer
// - PATCH /:id, DELETE /:id
// - GET /:id/pdf : generate/download PDF (watermark if plan = free)
// - POST /:id/send : email invoice with payment link
// - Public (no auth): GET /public/:token, POST /public/:token/pay -> Payscribe

router.get('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: list/filter invoices' });
});

router.post('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: create invoice' });
});

router.get('/:id', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: get invoice detail' });
});

router.patch('/:id', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: update invoice' });
});

router.delete('/:id', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: delete invoice' });
});

router.get('/:id/pdf', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: generate invoice PDF' });
});

router.post('/:id/send', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: email invoice to client' });
});

module.exports = router;

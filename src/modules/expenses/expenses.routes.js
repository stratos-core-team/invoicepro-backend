const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO: CRUD on expenses, joined with tax_rates for VAT/GST calculation.

router.get('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: list expenses' });
});

router.post('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: log expense' });
});

module.exports = router;

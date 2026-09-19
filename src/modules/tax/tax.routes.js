const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO: CRUD on tax_rates (configurable, clearly-labeled per PRD Risk & Mitigation).

router.get('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: list tax rates' });
});

router.post('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: create tax rate' });
});

module.exports = router;

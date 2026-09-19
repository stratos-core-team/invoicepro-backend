const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO: aggregate query -> total invoices, total revenue, unpaid count,
// pending amount, recent activity. Must load in < 2s per NFRs — consider
// a materialized view or cached counters if this gets slow at scale.

router.get('/summary', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: dashboard summary' });
});

module.exports = router;

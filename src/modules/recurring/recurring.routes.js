const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO: CRUD on recurring_schedules. Per PRD Risk & Mitigation, creation should
// require an explicit confirmation step client-side before activating.
// The actual "generate invoice from schedule" logic belongs in src/jobs/recurringBilling.job.js
// (a node-cron job that scans recurring_schedules WHERE next_run_date <= today AND status = 'active').

router.get('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: list recurring schedules' });
});

router.post('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: create recurring schedule' });
});

router.patch('/:id', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: edit/pause/cancel schedule' });
});

module.exports = router;

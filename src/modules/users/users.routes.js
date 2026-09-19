const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO: Profile Setup step (business_profiles table), onboarding_completed_at update,
// GET /me, PATCH /me, POST /me/logo

router.get('/me', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: fetch current user profile' });
});

router.patch('/me', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: update business profile' });
});

module.exports = router;

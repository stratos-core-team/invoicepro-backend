const express = require('express');
const { requireAuth } = require('../../middleware/auth.middleware');

const router = express.Router();

// TODO: CRUD against `customers` table, scoped to req.user.id.
// Also: POST /from-contacts for the "Add Customer from Phone Contacts" feature
// (accepts { name, phone } selected client-side; source = 'contacts').

router.get('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: list customers' });
});

router.post('/', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: create customer' });
});

router.get('/:id', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: get customer + invoice history' });
});

router.patch('/:id', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: update customer' });
});

router.delete('/:id', requireAuth, (req, res) => {
  res.status(501).json({ success: false, message: 'Not implemented yet: delete customer' });
});

module.exports = router;

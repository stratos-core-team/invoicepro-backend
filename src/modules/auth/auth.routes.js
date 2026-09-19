const express = require('express');
const controller = require('./auth.controller');
const validate = require('../../middleware/validate.middleware');
const { registerSchema, loginSchema, refreshSchema } = require('./auth.validation');

const router = express.Router();

router.post('/register', validate(registerSchema), controller.register);
router.post('/login', validate(loginSchema), controller.login);
router.post('/refresh', validate(refreshSchema), controller.refresh);
router.post('/logout', validate(refreshSchema), controller.logout);

module.exports = router;

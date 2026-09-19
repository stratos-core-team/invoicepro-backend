const { Router } = require('express');
const controller = require('../controllers/auth.controller');
const authenticate = require('../middlewares/authenticate');
const validate = require('../middlewares/validate');
const { registerSchema, loginSchema, profileSetupSchema } = require('../validators/auth.validators');

const router = Router();

// --- Public ---
router.post('/register', validate({ body: registerSchema }), controller.register);
router.post('/login', validate({ body: loginSchema }), controller.login);
router.post('/refresh', controller.refresh); // reads refresh token from httpOnly cookie
router.post('/logout', controller.logout);

// --- Requires a valid access token ---
router.get('/me', authenticate, controller.me);
router.post(
  '/profile-setup',
  authenticate,
  validate({ body: profileSetupSchema }),
  controller.completeProfile
);
router.post('/onboarding-complete', authenticate, controller.completeOnboarding);

module.exports = router;

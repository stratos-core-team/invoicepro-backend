const { Router } = require('express');
const controller = require('../controllers/dashboard.controller');
const authenticate = require('../middlewares/authenticate');

const router = Router();

router.get('/', authenticate, controller.getDashboard);

module.exports = router;

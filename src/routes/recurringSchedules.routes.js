const { Router } = require('express');
const controller = require('../controllers/recurringSchedules.controller');
const authenticate = require('../middlewares/authenticate');
const validate = require('../middlewares/validate');
const {
  createScheduleSchema,
  updateScheduleSchema,
  listQuerySchema,
  idParamSchema,
} = require('../validators/recurringSchedules.validators');

const router = Router();

router.use(authenticate);

router.post('/', validate({ body: createScheduleSchema }), controller.create);
router.get('/', validate({ query: listQuerySchema }), controller.list);

// Dev/testing helper: generate this user's due invoices right now instead of waiting for the cron job.
router.post('/run-due', controller.runDue);

router.get('/:id', validate({ params: idParamSchema }), controller.getOne);
router.patch(
  '/:id',
  validate({ params: idParamSchema, body: updateScheduleSchema }),
  controller.update
);
router.post('/:id/confirm', validate({ params: idParamSchema }), controller.confirm);
router.post('/:id/pause', validate({ params: idParamSchema }), controller.pause);
router.post('/:id/resume', validate({ params: idParamSchema }), controller.resume);
router.post('/:id/cancel', validate({ params: idParamSchema }), controller.cancel);

module.exports = router;

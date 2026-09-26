const { Router } = require('express');
const controller = require('../controllers/invoices.controller');
const authenticate = require('../middlewares/authenticate');
const validate = require('../middlewares/validate');
const {
  createInvoiceSchema,
  updateInvoiceSchema,
  listQuerySchema,
  idParamSchema,
} = require('../validators/invoices.validators');

const router = Router();

router.use(authenticate);

router.post('/', validate({ body: createInvoiceSchema }), controller.create);
router.get('/', validate({ query: listQuerySchema }), controller.list);
router.get('/:id', validate({ params: idParamSchema }), controller.getOne);
router.patch(
  '/:id',
  validate({ params: idParamSchema, body: updateInvoiceSchema }),
  controller.update
);
router.delete('/:id', validate({ params: idParamSchema }), controller.remove);

// Status transitions
router.post('/:id/send', validate({ params: idParamSchema }), controller.send);
router.post('/:id/mark-paid', validate({ params: idParamSchema }), controller.markPaid);

module.exports = router;

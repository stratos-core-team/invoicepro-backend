const { Router } = require('express');
const controller = require('../controllers/customers.controller');
const authenticate = require('../middlewares/authenticate');
const validate = require('../middlewares/validate');
const {
  createCustomerSchema,
  updateCustomerSchema,
  idParamSchema,
  listQuerySchema,
} = require('../validators/customers.validators');

const router = Router();

router.use(authenticate); // every customer route requires a logged-in user

router.post('/', validate({ body: createCustomerSchema }), controller.create);
router.get('/', validate({ query: listQuerySchema }), controller.list);
router.get('/:id', validate({ params: idParamSchema }), controller.getOne);
router.get('/:id/invoices', validate({ params: idParamSchema }), controller.invoiceHistory);
router.patch(
  '/:id',
  validate({ params: idParamSchema, body: updateCustomerSchema }),
  controller.update
);
router.delete('/:id', validate({ params: idParamSchema }), controller.remove);

module.exports = router;

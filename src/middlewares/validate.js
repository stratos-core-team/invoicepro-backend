const AppError = require('../utils/AppError');

/**
 * Validates req.body / req.params / req.query against Zod schemas.
 *
 * Usage:
 *   router.post('/customers', validate({ body: createCustomerSchema }), controller.create);
 */
function validate(schemas) {
  return (req, res, next) => {
    for (const key of ['body', 'params', 'query']) {
      const schema = schemas[key];
      if (!schema) continue;

      const result = schema.safeParse(req[key]);
      if (!result.success) {
        const details = result.error.issues.map((issue) => ({
          field: issue.path.join('.'),
          message: issue.message,
        }));
        throw AppError.badRequest('Validation failed', details);
      }
      req[key] = result.data;
    }
    next();
  };
}

module.exports = validate;

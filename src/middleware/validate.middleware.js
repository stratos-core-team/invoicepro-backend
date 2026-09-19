const ApiError = require('../utils/ApiError');

/**
 * Usage: router.post('/', validate(schema), controller.create)
 * Validates req.body (default) or another request part via `part`.
 */
function validate(schema, part = 'body') {
  return (req, res, next) => {
    const { error, value } = schema.validate(req[part], {
      abortEarly: false,
      stripUnknown: true,
    });

    if (error) {
      const details = error.details.map((d) => d.message);
      throw ApiError.badRequest('Validation failed', details);
    }

    req[part] = value;
    next();
  };
}

module.exports = validate;

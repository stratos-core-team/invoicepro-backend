/**
 * Consistent response envelope used across all endpoints:
 *   { success: true, data, meta }
 *   { success: false, message, details }
 */
function ok(res, data = null, meta = undefined, statusCode = 200) {
  const body = { success: true, data };
  if (meta) body.meta = meta;
  return res.status(statusCode).json(body);
}

function created(res, data) {
  return ok(res, data, undefined, 201);
}

module.exports = { ok, created };

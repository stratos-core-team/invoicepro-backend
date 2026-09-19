/**
 * Wraps an async route handler so rejected promises reach the error middleware.
 * Note: app.js also loads `express-async-errors`, which does this automatically.
 * This wrapper is kept for explicitness / in case that package is ever removed.
 */
const asyncHandler = (fn) => (req, res, next) => {
  Promise.resolve(fn(req, res, next)).catch(next);
};

module.exports = asyncHandler;

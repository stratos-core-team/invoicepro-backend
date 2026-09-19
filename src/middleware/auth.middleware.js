const ApiError = require('../utils/ApiError');
const { verifyAccessToken } = require('../utils/token');

/**
 * Requires a valid access token in the Authorization header.
 * On success, attaches { id, email } to req.user.
 */
function requireAuth(req, res, next) {
  const header = req.headers.authorization || '';
  const [scheme, token] = header.split(' ');

  if (scheme !== 'Bearer' || !token) {
    throw ApiError.unauthorized('Missing or malformed Authorization header');
  }

  try {
    const payload = verifyAccessToken(token);
    req.user = { id: payload.sub, email: payload.email };
    next();
  } catch (err) {
    throw ApiError.unauthorized('Invalid or expired access token');
  }
}

module.exports = { requireAuth };

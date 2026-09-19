const jwt = require('jsonwebtoken');
const crypto = require('crypto');
const env = require('../config/env');

/** Short-lived JWT sent in the response body, used for Authorization: Bearer <token> */
function signAccessToken(user) {
  return jwt.sign({ sub: user.id, email: user.email }, env.jwt.accessSecret, {
    expiresIn: env.jwt.accessExpiresIn,
  });
}

/**
 * Opaque, high-entropy refresh token (not a JWT). Kept simple on purpose:
 * revocation is just a DB row flip, no need to decode/verify a signature
 * for something we look up by hash anyway.
 */
function generateRefreshToken() {
  return crypto.randomBytes(64).toString('hex');
}

/** We only ever store/compare the hash of a refresh token, never the raw value. */
function hashToken(token) {
  return crypto.createHash('sha256').update(token).digest('hex');
}

function parseDurationToMs(duration) {
  const match = /^(\d+)(s|m|h|d)$/.exec(duration);
  if (!match) {
    throw new Error(`Invalid duration string: "${duration}" (expected e.g. "15m", "30d")`);
  }
  const value = Number(match[1]);
  const multipliers = { s: 1000, m: 60 * 1000, h: 60 * 60 * 1000, d: 24 * 60 * 60 * 1000 };
  return value * multipliers[match[2]];
}

function refreshTokenExpiryDate() {
  return new Date(Date.now() + parseDurationToMs(env.jwt.refreshExpiresIn));
}

module.exports = {
  signAccessToken,
  generateRefreshToken,
  hashToken,
  refreshTokenExpiryDate,
  parseDurationToMs,
};

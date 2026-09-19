const { query } = require('../../config/db');
const { hashPassword, comparePassword } = require('../../utils/password');
const { signAccessToken, signRefreshToken, verifyRefreshToken, hashToken } = require('../../utils/token');
const ApiError = require('../../utils/ApiError');
const env = require('../../config/env');
const logger = require('../../utils/logger');
// TODO: wire real provider (Resend). Stubbed so the flow is complete end-to-end.
const { sendWelcomeEmail } = require('../../utils/mailer');

async function registerUser({ fullName, businessName, email, password }) {
  const existing = await query('SELECT id FROM users WHERE email = $1', [email.toLowerCase()]);
  if (existing.rowCount > 0) {
    throw ApiError.conflict('An account with this email already exists');
  }

  const passwordHash = await hashPassword(password);

  const result = await query(
    `INSERT INTO users (full_name, business_name, email, password_hash)
     VALUES ($1, $2, $3, $4)
     RETURNING id, full_name, business_name, email, plan, created_at`,
    [fullName, businessName, email.toLowerCase(), passwordHash]
  );

  const user = result.rows[0];

  // Fire-and-forget: registration should not fail if the email send has a hiccup.
  // In production this should go through a retry queue (see PRD Risk & Mitigation).
  sendWelcomeEmail(user.email, user.full_name).catch((err) =>
    logger.error('Failed to send confirmation email', err)
  );

  const tokens = await issueTokenPair(user.id, user.email);

  return { user, ...tokens };
}

async function loginUser({ email, password, totpCode }) {
  const result = await query(
    `SELECT id, full_name, business_name, email, password_hash, totp_enabled, totp_secret, plan
     FROM users WHERE email = $1`,
    [email.toLowerCase()]
  );

  if (result.rowCount === 0) {
    throw ApiError.unauthorized('Invalid email or password');
  }

  const user = result.rows[0];
  const passwordMatches = await comparePassword(password, user.password_hash);
  if (!passwordMatches) {
    throw ApiError.unauthorized('Invalid email or password');
  }

  if (user.totp_enabled) {
    if (!totpCode) {
      // Signal to the client that a second factor is required before issuing tokens.
      throw new ApiError(401, 'TOTP code required', { requiresTotp: true });
    }
    // TODO: verify totpCode against user.totp_secret using a TOTP library (e.g. otplib)
    // when the 2FA module is implemented.
  }

  const tokens = await issueTokenPair(user.id, user.email);

  delete user.password_hash;
  delete user.totp_secret;

  return { user, ...tokens };
}

async function issueTokenPair(userId, email) {
  const accessToken = signAccessToken({ sub: userId, email });
  const refreshToken = signRefreshToken({ sub: userId });

  const decoded = verifyRefreshToken(refreshToken);
  const expiresAt = new Date(decoded.exp * 1000);

  await query(
    `INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES ($1, $2, $3)`,
    [userId, hashToken(refreshToken), expiresAt]
  );

  return { accessToken, refreshToken };
}

async function refreshAccessToken(refreshToken) {
  let decoded;
  try {
    decoded = verifyRefreshToken(refreshToken);
  } catch (err) {
    throw ApiError.unauthorized('Invalid or expired refresh token');
  }

  const tokenHash = hashToken(refreshToken);
  const result = await query(
    `SELECT rt.id, rt.revoked_at, u.id AS user_id, u.email
     FROM refresh_tokens rt
     JOIN users u ON u.id = rt.user_id
     WHERE rt.token_hash = $1 AND rt.expires_at > now()`,
    [tokenHash]
  );

  if (result.rowCount === 0 || result.rows[0].revoked_at) {
    throw ApiError.unauthorized('Refresh token is no longer valid');
  }

  const { user_id: userId, email } = result.rows[0];
  const accessToken = signAccessToken({ sub: userId, email });

  return { accessToken };
}

async function logoutUser(refreshToken) {
  await query(
    `UPDATE refresh_tokens SET revoked_at = now() WHERE token_hash = $1`,
    [hashToken(refreshToken)]
  );
}

module.exports = { registerUser, loginUser, refreshAccessToken, logoutUser };

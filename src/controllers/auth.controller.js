const authService = require('../services/auth.service');
const { ok, created } = require('../utils/apiResponse');
const env = require('../config/env');

const REFRESH_COOKIE_NAME = 'refreshToken';

// Scoped to /api/v1/auth so the cookie is only ever sent to refresh/logout —
// keep in sync with JWT_REFRESH_EXPIRES_IN in .env.
function refreshCookieOptions() {
  return {
    httpOnly: true,
    secure: env.isProd,
    sameSite: 'lax',
    path: '/api/v1/auth',
    maxAge: 30 * 24 * 60 * 60 * 1000, // 30 days
  };
}

function requestMeta(req) {
  return { userAgent: req.headers['user-agent'], ipAddress: req.ip };
}

async function register(req, res) {
  const result = await authService.register(req.body, requestMeta(req));
  res.cookie(REFRESH_COOKIE_NAME, result.refreshToken, refreshCookieOptions());
  return created(res, { user: result.user, accessToken: result.accessToken });
}

async function login(req, res) {
  const result = await authService.login(req.body, requestMeta(req));

  if (result.requiresTotp) {
    // No tokens issued yet — client should call a future /auth/totp/verify
    // endpoint with userId + 6-digit code to complete login.
    return ok(res, { requiresTotp: true, userId: result.userId });
  }

  res.cookie(REFRESH_COOKIE_NAME, result.refreshToken, refreshCookieOptions());
  return ok(res, { user: result.user, accessToken: result.accessToken });
}

async function refresh(req, res) {
  const token = req.cookies?.[REFRESH_COOKIE_NAME];
  const result = await authService.refresh(token, requestMeta(req));
  res.cookie(REFRESH_COOKIE_NAME, result.refreshToken, refreshCookieOptions());
  return ok(res, { user: result.user, accessToken: result.accessToken });
}

async function logout(req, res) {
  const token = req.cookies?.[REFRESH_COOKIE_NAME];
  await authService.logout(token);
  res.clearCookie(REFRESH_COOKIE_NAME, { path: '/api/v1/auth' });
  return ok(res, { loggedOut: true });
}

async function me(req, res) {
  const user = await authService.getMe(req.user.id);
  return ok(res, { user });
}

async function completeProfile(req, res) {
  const user = await authService.completeProfile(req.user.id, req.body);
  return ok(res, { user });
}

async function completeOnboarding(req, res) {
  const result = await authService.completeOnboarding(req.user.id);
  return ok(res, result);
}

module.exports = { register, login, refresh, logout, me, completeProfile, completeOnboarding };

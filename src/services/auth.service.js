const AppError = require('../utils/AppError');
const { hashPassword, comparePassword } = require('../utils/password');
const {
  signAccessToken,
  generateRefreshToken,
  hashToken,
  refreshTokenExpiryDate,
} = require('../utils/tokens');
const usersRepo = require('../repositories/users.repository');
const refreshTokensRepo = require('../repositories/refreshTokens.repository');
const emailService = require('./email.service');

/** Never leak password_hash / totp_secret to the client. */
function toPublicUser(user) {
  return {
    id: user.id,
    fullName: user.full_name,
    email: user.email,
    businessName: user.business_name,
    businessLogoUrl: user.business_logo_url,
    contactPhone: user.contact_phone,
    contactAddress: user.contact_address,
    planType: user.plan_type,
    profileCompletedAt: user.profile_completed_at,
    onboardingCompletedAt: user.onboarding_completed_at,
    totpEnabled: user.totp_enabled,
    createdAt: user.created_at,
  };
}

async function issueTokenPair(user, meta = {}) {
  const accessToken = signAccessToken(user);
  const refreshToken = generateRefreshToken();
  await refreshTokensRepo.create({
    userId: user.id,
    tokenHash: hashToken(refreshToken),
    expiresAt: refreshTokenExpiryDate(),
    userAgent: meta.userAgent,
    ipAddress: meta.ipAddress,
  });
  return { accessToken, refreshToken };
}

async function register({ fullName, email, password, businessName }, meta) {
  const normalizedEmail = email.toLowerCase();
  const existing = await usersRepo.findByEmail(normalizedEmail);
  if (existing) {
    throw AppError.conflict('An account with this email already exists');
  }

  const passwordHash = await hashPassword(password);
  const user = await usersRepo.createUser({
    fullName,
    email: normalizedEmail,
    passwordHash,
    businessName,
  });

  // Don't fail registration just because the email provider hiccuped —
  // log it and move on; the PRD's retry-queue risk mitigation belongs here later.
  emailService.sendConfirmationEmail(user).catch((err) => {
    // eslint-disable-next-line no-console
    console.error('Failed to send confirmation email:', err.message);
  });

  const tokens = await issueTokenPair(user, meta);
  return { user: toPublicUser(user), ...tokens };
}

async function login({ email, password }, meta) {
  const user = await usersRepo.findByEmail(email.toLowerCase());
  if (!user) {
    throw AppError.unauthorized('Invalid email or password');
  }

  const passwordMatches = await comparePassword(password, user.password_hash);
  if (!passwordMatches) {
    throw AppError.unauthorized('Invalid email or password');
  }

  if (user.totp_enabled) {
    // Caller (controller) is responsible for prompting for the TOTP code
    // via a separate /auth/totp/verify endpoint before tokens are issued.
    return { requiresTotp: true, userId: user.id };
  }

  const tokens = await issueTokenPair(user, meta);
  return { user: toPublicUser(user), ...tokens };
}

async function refresh(refreshToken, meta) {
  if (!refreshToken) {
    throw AppError.unauthorized('Missing refresh token');
  }

  const stored = await refreshTokensRepo.findValidByHash(hashToken(refreshToken));
  if (!stored) {
    throw AppError.unauthorized('Invalid or expired refresh token');
  }

  const user = await usersRepo.findById(stored.user_id);
  if (!user) {
    throw AppError.unauthorized('User no longer exists');
  }

  // Rotation: the old token is single-use — revoke it, issue a fresh pair.
  await refreshTokensRepo.revokeById(stored.id);
  const tokens = await issueTokenPair(user, meta);
  return { user: toPublicUser(user), ...tokens };
}

async function logout(refreshToken) {
  if (!refreshToken) return;
  const stored = await refreshTokensRepo.findValidByHash(hashToken(refreshToken));
  if (stored) {
    await refreshTokensRepo.revokeById(stored.id);
  }
}

async function completeProfile(userId, profileData) {
  const user = await usersRepo.updateProfile(userId, profileData);
  if (!user) throw AppError.notFound('User not found');
  return toPublicUser(user);
}

async function completeOnboarding(userId) {
  const result = await usersRepo.completeOnboarding(userId);
  if (!result) throw AppError.notFound('User not found');
  return result;
}

async function getMe(userId) {
  const user = await usersRepo.findById(userId);
  if (!user) throw AppError.notFound('User not found');
  return toPublicUser(user);
}

module.exports = {
  toPublicUser,
  register,
  login,
  refresh,
  logout,
  completeProfile,
  completeOnboarding,
  getMe,
};

const { query } = require('../config/db');

async function createUser({ fullName, email, passwordHash, businessName }) {
  const { rows } = await query(
    `INSERT INTO users (full_name, email, password_hash, business_name)
     VALUES ($1, $2, $3, $4)
     RETURNING *`,
    [fullName, email, passwordHash, businessName]
  );
  return rows[0];
}

async function findByEmail(email) {
  const { rows } = await query('SELECT * FROM users WHERE email = $1', [email]);
  return rows[0] || null;
}

async function findById(id) {
  const { rows } = await query('SELECT * FROM users WHERE id = $1', [id]);
  return rows[0] || null;
}

async function markEmailVerified(id) {
  await query('UPDATE users SET email_verified_at = now() WHERE id = $1', [id]);
}

async function updateProfile(id, { businessName, contactPhone, contactAddress, businessLogoUrl }) {
  const { rows } = await query(
    `UPDATE users
     SET business_name = COALESCE($2, business_name),
         contact_phone = COALESCE($3, contact_phone),
         contact_address = COALESCE($4, contact_address),
         business_logo_url = COALESCE($5, business_logo_url),
         profile_completed_at = COALESCE(profile_completed_at, now())
     WHERE id = $1
     RETURNING *`,
    [id, businessName ?? null, contactPhone ?? null, contactAddress ?? null, businessLogoUrl ?? null]
  );
  return rows[0] || null;
}

async function completeOnboarding(id) {
  const { rows } = await query(
    `UPDATE users SET onboarding_completed_at = now() WHERE id = $1
     RETURNING id, onboarding_completed_at`,
    [id]
  );
  return rows[0] || null;
}

async function setTotpSecret(id, secret) {
  await query('UPDATE users SET totp_secret = $2 WHERE id = $1', [id, secret]);
}

async function enableTotp(id) {
  await query('UPDATE users SET totp_enabled = true WHERE id = $1', [id]);
}

module.exports = {
  createUser,
  findByEmail,
  findById,
  markEmailVerified,
  updateProfile,
  completeOnboarding,
  setTotpSecret,
  enableTotp,
};

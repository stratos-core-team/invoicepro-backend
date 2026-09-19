const { query } = require('../config/db');

async function create({ userId, tokenHash, expiresAt, userAgent, ipAddress }) {
  const { rows } = await query(
    `INSERT INTO refresh_tokens (user_id, token_hash, expires_at, user_agent, ip_address)
     VALUES ($1, $2, $3, $4, $5)
     RETURNING id`,
    [userId, tokenHash, expiresAt, userAgent || null, ipAddress || null]
  );
  return rows[0];
}

async function findValidByHash(tokenHash) {
  const { rows } = await query(
    `SELECT * FROM refresh_tokens
     WHERE token_hash = $1 AND revoked_at IS NULL AND expires_at > now()`,
    [tokenHash]
  );
  return rows[0] || null;
}

async function revokeById(id) {
  await query('UPDATE refresh_tokens SET revoked_at = now() WHERE id = $1', [id]);
}

async function revokeAllForUser(userId) {
  await query(
    'UPDATE refresh_tokens SET revoked_at = now() WHERE user_id = $1 AND revoked_at IS NULL',
    [userId]
  );
}

module.exports = { create, findValidByHash, revokeById, revokeAllForUser };

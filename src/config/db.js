const { Pool } = require('pg');
const env = require('./env');

const pool = new Pool({
  connectionString: env.db.connectionString,
  ssl: env.db.ssl,
  max: 20, // max clients in the pool — tune per deployment size
  idleTimeoutMillis: 30000,
  connectionTimeoutMillis: 5000,
});

pool.on('error', (err) => {
  // Handles errors on idle clients so one bad connection doesn't crash the process
  // eslint-disable-next-line no-console
  console.error('Unexpected PostgreSQL pool error', err);
});

/**
 * Run a single query against the pool.
 * @param {string} text - SQL text with $1, $2... placeholders
 * @param {Array} params
 */
function query(text, params) {
  return pool.query(text, params);
}

/**
 * Get a dedicated client for multi-statement transactions.
 * Always release() it in a finally block.
 *
 * Usage:
 *   const client = await getClient();
 *   try {
 *     await client.query('BEGIN');
 *     ...
 *     await client.query('COMMIT');
 *   } catch (e) {
 *     await client.query('ROLLBACK');
 *     throw e;
 *   } finally {
 *     client.release();
 *   }
 */
async function getClient() {
  const client = await pool.connect();
  return client;
}

/**
 * Convenience wrapper: runs `fn` inside a BEGIN/COMMIT transaction,
 * automatically rolling back on error.
 */
async function withTransaction(fn) {
  const client = await getClient();
  try {
    await client.query('BEGIN');
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (err) {
    await client.query('ROLLBACK');
    throw err;
  } finally {
    client.release();
  }
}

module.exports = { pool, query, getClient, withTransaction };

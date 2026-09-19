/**
 * One-shot schema applier for local/dev setup.
 * For production, migrate to a proper migration tool (e.g. node-pg-migrate)
 * once the schema starts changing incrementally instead of wholesale.
 *
 * Usage: npm run db:create-schema
 */
const fs = require('fs');
const path = require('path');
const { pool } = require('../config/db');
const logger = require('../utils/logger');

async function main() {
  const sql = fs.readFileSync(path.join(__dirname, 'schema.sql'), 'utf8');
  logger.info('Applying schema.sql to database...');
  await pool.query(sql);
  logger.info('Schema applied successfully.');
  await pool.end();
}

main().catch((err) => {
  logger.error('Failed to apply schema', err);
  process.exit(1);
});

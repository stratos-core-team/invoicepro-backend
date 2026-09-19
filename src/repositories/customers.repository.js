const { query } = require('../config/db');

async function create({ userId, name, email, phone, address, source }) {
  const { rows } = await query(
    `INSERT INTO customers (user_id, name, email, phone, address, source)
     VALUES ($1, $2, $3, $4, $5, COALESCE($6, 'manual'))
     RETURNING *`,
    [userId, name, email || null, phone || null, address || null, source || null]
  );
  return rows[0];
}

/** Every read/write is scoped by user_id so one user can never touch another's customers. */
async function findByIdForUser(id, userId) {
  const { rows } = await query('SELECT * FROM customers WHERE id = $1 AND user_id = $2', [
    id,
    userId,
  ]);
  return rows[0] || null;
}

async function list({ userId, search, page, limit }) {
  const offset = (page - 1) * limit;
  const params = [userId];
  let whereClause = 'WHERE user_id = $1';

  if (search) {
    params.push(`%${search}%`);
    whereClause += ` AND (name ILIKE $${params.length} OR email ILIKE $${params.length} OR phone ILIKE $${params.length})`;
  }

  params.push(limit, offset);
  const rowsQuery = `
    SELECT * FROM customers
    ${whereClause}
    ORDER BY name ASC
    LIMIT $${params.length - 1} OFFSET $${params.length}
  `;
  const countQuery = `SELECT COUNT(*)::int AS total FROM customers ${whereClause}`;

  const [rowsResult, countResult] = await Promise.all([
    query(rowsQuery, params),
    query(countQuery, params.slice(0, params.length - 2)),
  ]);

  return { rows: rowsResult.rows, total: countResult.rows[0].total };
}

async function update(id, userId, fields) {
  const { rows } = await query(
    `UPDATE customers
     SET name = COALESCE($3, name),
         email = COALESCE($4, email),
         phone = COALESCE($5, phone),
         address = COALESCE($6, address)
     WHERE id = $1 AND user_id = $2
     RETURNING *`,
    [id, userId, fields.name ?? null, fields.email ?? null, fields.phone ?? null, fields.address ?? null]
  );
  return rows[0] || null;
}

async function remove(id, userId) {
  const { rowCount } = await query('DELETE FROM customers WHERE id = $1 AND user_id = $2', [
    id,
    userId,
  ]);
  return rowCount > 0;
}

/** Reads directly from the invoices table (already migrated) for the customer's billing history. */
async function getInvoiceHistory(customerId, userId) {
  const { rows } = await query(
    `SELECT id, invoice_number, status, currency, total_amount, issue_date, due_date, paid_at, created_at
     FROM invoices
     WHERE customer_id = $1 AND user_id = $2
     ORDER BY created_at DESC`,
    [customerId, userId]
  );
  return rows;
}

module.exports = { create, findByIdForUser, list, update, remove, getInvoiceHistory };

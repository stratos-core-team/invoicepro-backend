const { query, withTransaction } = require('../config/db');

/**
 * Creates the invoice header + all its line items in a single transaction.
 * The invoice number is generated inside the same transaction by atomically
 * incrementing users.invoice_sequence (row-level lock), so two concurrent
 * requests for the same user can never collide on the same number.
 *
 * `items` must already have `lineTotal` computed by the service layer —
 * this repository does no arithmetic, only persistence.
 */
async function createWithItems({
  userId,
  customerId,
  currency,
  subtotal,
  taxAmount,
  totalAmount,
  paymentTerms,
  notes,
  dueDate,
  isWatermarked,
  items,
}) {
  return withTransaction(async (client) => {
    const seqResult = await client.query(
      `UPDATE users SET invoice_sequence = invoice_sequence + 1 WHERE id = $1 RETURNING invoice_sequence`,
      [userId]
    );
    const invoiceNumber = `INV-${String(seqResult.rows[0].invoice_sequence).padStart(4, '0')}`;

    const { rows } = await client.query(
      `INSERT INTO invoices
         (user_id, customer_id, invoice_number, currency, subtotal, tax_amount, total_amount, payment_terms, notes, due_date, is_watermarked)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
       RETURNING *`,
      [
        userId,
        customerId,
        invoiceNumber,
        currency,
        subtotal,
        taxAmount,
        totalAmount,
        paymentTerms || null,
        notes || null,
        dueDate,
        isWatermarked,
      ]
    );
    const invoice = rows[0];

    const insertedItems = [];
    for (let i = 0; i < items.length; i += 1) {
      const item = items[i];
      const { rows: itemRows } = await client.query(
        `INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total, position)
         VALUES ($1, $2, $3, $4, $5, $6)
         RETURNING *`,
        [invoice.id, item.description, item.quantity, item.unitPrice, item.lineTotal, i]
      );
      insertedItems.push(itemRows[0]);
    }

    return { ...invoice, items: insertedItems };
  });
}

/** Updates the invoice header and fully replaces its line items in one transaction. */
async function updateWithItems({
  id,
  userId,
  customerId,
  subtotal,
  taxAmount,
  totalAmount,
  paymentTerms,
  notes,
  dueDate,
  items,
}) {
  return withTransaction(async (client) => {
    const { rows } = await client.query(
      `UPDATE invoices
       SET customer_id = $3, subtotal = $4, tax_amount = $5, total_amount = $6,
           payment_terms = $7, notes = $8, due_date = $9
       WHERE id = $1 AND user_id = $2
       RETURNING *`,
      [id, userId, customerId, subtotal, taxAmount, totalAmount, paymentTerms || null, notes || null, dueDate]
    );
    const invoice = rows[0];
    if (!invoice) return null;

    await client.query('DELETE FROM invoice_items WHERE invoice_id = $1', [id]);

    const insertedItems = [];
    for (let i = 0; i < items.length; i += 1) {
      const item = items[i];
      const { rows: itemRows } = await client.query(
        `INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total, position)
         VALUES ($1, $2, $3, $4, $5, $6)
         RETURNING *`,
        [id, item.description, item.quantity, item.unitPrice, item.lineTotal, i]
      );
      insertedItems.push(itemRows[0]);
    }

    return { ...invoice, items: insertedItems };
  });
}

/** Fetches one invoice (with its line items and a snapshot of the customer's name/email) scoped to the owning user. */
async function findByIdForUser(id, userId) {
  const { rows } = await query(
    `SELECT i.*, c.name AS customer_name, c.email AS customer_email
     FROM invoices i
     JOIN customers c ON c.id = i.customer_id
     WHERE i.id = $1 AND i.user_id = $2`,
    [id, userId]
  );
  if (!rows[0]) return null;

  const itemsResult = await query(
    'SELECT * FROM invoice_items WHERE invoice_id = $1 ORDER BY position ASC',
    [id]
  );
  return { ...rows[0], items: itemsResult.rows };
}

async function list({ userId, status, search, page, limit }) {
  const offset = (page - 1) * limit;
  const params = [userId];
  let where = 'WHERE i.user_id = $1';

  if (status) {
    params.push(status);
    where += ` AND i.status = $${params.length}`;
  }
  if (search) {
    params.push(`%${search}%`);
    where += ` AND (c.name ILIKE $${params.length} OR i.invoice_number ILIKE $${params.length})`;
  }

  const limitParams = [...params, limit, offset];
  const rowsQuery = `
    SELECT i.*, c.name AS customer_name, c.email AS customer_email
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    ${where}
    ORDER BY i.created_at DESC
    LIMIT $${limitParams.length - 1} OFFSET $${limitParams.length}
  `;
  const countQuery = `
    SELECT COUNT(*)::int AS total
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    ${where}
  `;

  const [rowsResult, countResult] = await Promise.all([
    query(rowsQuery, limitParams),
    query(countQuery, params),
  ]);

  return { rows: rowsResult.rows, total: countResult.rows[0].total };
}

async function updateStatus(id, userId, { status, sentAt, paidAt }) {
  const { rows } = await query(
    `UPDATE invoices
     SET status = $3,
         sent_at = COALESCE($4, sent_at),
         paid_at = COALESCE($5, paid_at)
     WHERE id = $1 AND user_id = $2
     RETURNING *`,
    [id, userId, status, sentAt || null, paidAt || null]
  );
  return rows[0] || null;
}

async function remove(id, userId) {
  const { rowCount } = await query('DELETE FROM invoices WHERE id = $1 AND user_id = $2', [
    id,
    userId,
  ]);
  return rowCount > 0;
}

module.exports = {
  createWithItems,
  updateWithItems,
  findByIdForUser,
  list,
  updateStatus,
  remove,
};

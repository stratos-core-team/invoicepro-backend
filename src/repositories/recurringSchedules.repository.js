const { query } = require('../config/db');

async function create({ userId, customerId, template, frequency, nextRunDate }) {
  const { rows } = await query(
    `INSERT INTO recurring_schedules (user_id, customer_id, template, frequency, next_run_date)
     VALUES ($1, $2, $3, $4, $5)
     RETURNING *`,
    [userId, customerId, JSON.stringify(template), frequency, nextRunDate]
  );
  return rows[0];
}

async function findByIdForUser(id, userId) {
  const { rows } = await query(
    `SELECT s.*, c.name AS customer_name, c.email AS customer_email
     FROM recurring_schedules s
     JOIN customers c ON c.id = s.customer_id
     WHERE s.id = $1 AND s.user_id = $2`,
    [id, userId]
  );
  return rows[0] || null;
}

async function list({ userId, status, page, limit }) {
  const offset = (page - 1) * limit;
  const params = [userId];
  let where = 'WHERE s.user_id = $1';

  if (status) {
    params.push(status);
    where += ` AND s.status = $${params.length}`;
  }

  const limitParams = [...params, limit, offset];
  const rowsQuery = `
    SELECT s.*, c.name AS customer_name, c.email AS customer_email
    FROM recurring_schedules s
    JOIN customers c ON c.id = s.customer_id
    ${where}
    ORDER BY s.created_at DESC
    LIMIT $${limitParams.length - 1} OFFSET $${limitParams.length}
  `;
  const countQuery = `SELECT COUNT(*)::int AS total FROM recurring_schedules s ${where}`;

  const [rowsResult, countResult] = await Promise.all([
    query(rowsQuery, limitParams),
    query(countQuery, params),
  ]);

  return { rows: rowsResult.rows, total: countResult.rows[0].total };
}

async function update(id, userId, { customerId, template, frequency }) {
  const { rows } = await query(
    `UPDATE recurring_schedules
     SET customer_id = COALESCE($3, customer_id),
         template = COALESCE($4, template),
         frequency = COALESCE($5, frequency)
     WHERE id = $1 AND user_id = $2
     RETURNING *`,
    [id, userId, customerId ?? null, template ? JSON.stringify(template) : null, frequency ?? null]
  );
  return rows[0] || null;
}

async function updateStatus(id, userId, status) {
  const { rows } = await query(
    `UPDATE recurring_schedules SET status = $3 WHERE id = $1 AND user_id = $2 RETURNING *`,
    [id, userId, status]
  );
  return rows[0] || null;
}

/** Confirmation is a one-way flag — this only succeeds while confirmed_at is still NULL. */
async function confirm(id, userId) {
  const { rows } = await query(
    `UPDATE recurring_schedules
     SET confirmed_at = now()
     WHERE id = $1 AND user_id = $2 AND confirmed_at IS NULL
     RETURNING *`,
    [id, userId]
  );
  return rows[0] || null;
}

/**
 * Schedules ready to generate an invoice: active, explicitly confirmed by the
 * user (PRD risk mitigation), and due today or earlier.
 * With no `userId`, this is the system-wide query the cron job uses; with one,
 * it's scoped for the manual "run due now" testing endpoint.
 */
async function findDueSchedules({ userId } = {}) {
  const params = [];
  let where = "WHERE status = 'active' AND confirmed_at IS NOT NULL AND next_run_date <= current_date";

  if (userId) {
    params.push(userId);
    where += ` AND user_id = $${params.length}`;
  }

  const { rows } = await query(`SELECT * FROM recurring_schedules ${where}`, params);
  return rows;
}

async function advance(id, { lastRunDate, nextRunDate }) {
  const { rows } = await query(
    `UPDATE recurring_schedules SET last_run_date = $2, next_run_date = $3 WHERE id = $1 RETURNING *`,
    [id, lastRunDate, nextRunDate]
  );
  return rows[0] || null;
}

async function logGeneratedInvoice(scheduleId, invoiceId) {
  await query(
    `INSERT INTO recurring_invoice_log (recurring_schedule_id, invoice_id) VALUES ($1, $2)`,
    [scheduleId, invoiceId]
  );
}

async function getGenerationHistory(scheduleId, userId) {
  const { rows } = await query(
    `SELECT l.generated_at, i.id AS invoice_id, i.invoice_number, i.status, i.total_amount, i.currency
     FROM recurring_invoice_log l
     JOIN invoices i ON i.id = l.invoice_id
     WHERE l.recurring_schedule_id = $1 AND i.user_id = $2
     ORDER BY l.generated_at DESC`,
    [scheduleId, userId]
  );
  return rows;
}

module.exports = {
  create,
  findByIdForUser,
  list,
  update,
  updateStatus,
  confirm,
  findDueSchedules,
  advance,
  logGeneratedInvoice,
  getGenerationHistory,
};

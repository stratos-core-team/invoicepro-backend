const { query } = require('../config/db');

/**
 * One aggregate query using FILTER clauses, so the whole summary is a single
 * round trip (PRD: dashboard load < 2s).
 *
 * "Overdue" is computed from due_date rather than trusting the stored status,
 * so the numbers are correct even before the overdue-marking cron job exists.
 * Unpaid = sent or overdue (drafts and cancelled invoices are not owed yet).
 */
async function getSummary(userId) {
  const { rows } = await query(
    `SELECT
       COUNT(*)::int AS total_invoices,
       COUNT(*) FILTER (WHERE status = 'draft')::int AS draft_count,
       COUNT(*) FILTER (WHERE status IN ('sent', 'overdue'))::int AS unpaid_count,
       COUNT(*) FILTER (WHERE status IN ('sent', 'overdue') AND due_date < current_date)::int AS overdue_count,
       COALESCE(SUM(total_amount) FILTER (WHERE status = 'paid'), 0) AS total_revenue,
       COALESCE(SUM(total_amount) FILTER (
         WHERE status = 'paid' AND date_trunc('month', paid_at) = date_trunc('month', now())
       ), 0) AS revenue_this_month,
       COALESCE(SUM(total_amount) FILTER (WHERE status IN ('sent', 'overdue')), 0) AS pending_amount,
       COALESCE(SUM(total_amount) FILTER (
         WHERE status IN ('sent', 'overdue') AND due_date < current_date
       ), 0) AS overdue_amount
     FROM invoices
     WHERE user_id = $1 AND status <> 'cancelled'`,
    [userId]
  );
  return rows[0];
}

/** Most recently touched invoices, used to build the "recent activity" feed. */
async function getRecentInvoices(userId, limit) {
  const { rows } = await query(
    `SELECT i.id, i.invoice_number, i.status, i.currency, i.total_amount,
            i.created_at, i.sent_at, i.paid_at, i.updated_at,
            c.name AS customer_name
     FROM invoices i
     JOIN customers c ON c.id = i.customer_id
     WHERE i.user_id = $1
     ORDER BY i.updated_at DESC
     LIMIT $2`,
    [userId, limit]
  );
  return rows;
}

module.exports = { getSummary, getRecentInvoices };

const dashboardRepo = require('../repositories/dashboard.repository');

const RECENT_ACTIVITY_LIMIT = 10;

/**
 * There's no dedicated activity-log table yet, so the feed is derived from each
 * invoice's latest lifecycle event. Swap this for a real events table later
 * (e.g. when Payscribe webhooks and recurring billing start producing events).
 */
function toActivity(invoice) {
  let type = 'created';
  let occurredAt = invoice.created_at;

  if (invoice.status === 'paid' && invoice.paid_at) {
    type = 'paid';
    occurredAt = invoice.paid_at;
  } else if (invoice.sent_at) {
    type = 'sent';
    occurredAt = invoice.sent_at;
  }

  return {
    type, // 'created' | 'sent' | 'paid'
    invoiceId: invoice.id,
    invoiceNumber: invoice.invoice_number,
    customerName: invoice.customer_name,
    currency: invoice.currency,
    amount: invoice.total_amount,
    occurredAt,
  };
}

async function getDashboard(userId) {
  const [summary, recentInvoices] = await Promise.all([
    dashboardRepo.getSummary(userId),
    dashboardRepo.getRecentInvoices(userId, RECENT_ACTIVITY_LIMIT),
  ]);

  return {
    summary: {
      totalInvoices: summary.total_invoices,
      draftCount: summary.draft_count,
      unpaidCount: summary.unpaid_count,
      overdueCount: summary.overdue_count,
      // Money stays as strings (pg numeric) to avoid float rounding; clients parseFloat for display.
      totalRevenue: summary.total_revenue,
      revenueThisMonth: summary.revenue_this_month,
      pendingAmount: summary.pending_amount,
      overdueAmount: summary.overdue_amount,
    },
    recentActivity: recentInvoices.map(toActivity),
  };
}

module.exports = { toActivity, getDashboard };

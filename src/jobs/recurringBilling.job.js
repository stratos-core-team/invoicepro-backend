const cron = require('node-cron');
const logger = require('../utils/logger');
// const { query } = require('../config/db');

/**
 * Runs daily. Finds recurring_schedules where next_run_date <= today and
 * status = 'active', generates a new invoice from line_items_template for each,
 * advances next_run_date by the schedule's frequency, and logs the generated
 * invoice against the schedule (per PRD Risk & Mitigation: audit log).
 *
 * Left unscheduled (commented out below) until the invoices + recurring
 * modules are implemented, so it has something real to call.
 */
async function runRecurringBillingSweep() {
  logger.info('[recurringBilling] sweep started');
  // TODO: implement once invoices.service.js exposes createInvoiceFromTemplate()
  logger.info('[recurringBilling] sweep finished');
}

// cron.schedule('0 6 * * *', runRecurringBillingSweep); // 6am daily, uncomment when ready

module.exports = { runRecurringBillingSweep };

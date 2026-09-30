const AppError = require('../utils/AppError');
const recurringRepo = require('../repositories/recurringSchedules.repository');
const customersRepo = require('../repositories/customers.repository');
const invoicesService = require('./invoices.service');

function today() {
  return new Date().toISOString().slice(0, 10);
}

/**
 * Adds one billing interval to a 'YYYY-MM-DD' date, returning a new 'YYYY-MM-DD'.
 *
 * Known limitation: JS Date month arithmetic rolls overflow days into the next
 * month (e.g. 31 Jan + 1 month -> 3 Mar, not 28/29 Feb), same as most calendar
 * libraries without special-casing. Fine for weekly/quarterly/yearly; worth
 * revisiting if a lot of schedules start on the 29th-31st of a month.
 */
function addInterval(dateStr, frequency) {
  const d = new Date(`${dateStr}T00:00:00Z`);
  switch (frequency) {
    case 'weekly':
      d.setUTCDate(d.getUTCDate() + 7);
      break;
    case 'monthly':
      d.setUTCMonth(d.getUTCMonth() + 1);
      break;
    case 'quarterly':
      d.setUTCMonth(d.getUTCMonth() + 3);
      break;
    case 'yearly':
      d.setUTCFullYear(d.getUTCFullYear() + 1);
      break;
    default:
      throw new Error(`Unknown frequency: ${frequency}`);
  }
  return d.toISOString().slice(0, 10);
}

function toPublicSchedule(schedule) {
  return {
    id: schedule.id,
    customerId: schedule.customer_id,
    customerName: schedule.customer_name,
    customerEmail: schedule.customer_email,
    frequency: schedule.frequency,
    status: schedule.status,
    template: schedule.template, // { items, taxPercent, paymentTerms, notes, currency }
    nextRunDate: schedule.next_run_date,
    lastRunDate: schedule.last_run_date,
    confirmedAt: schedule.confirmed_at,
    createdAt: schedule.created_at,
    updatedAt: schedule.updated_at,
  };
}

async function createSchedule(userId, data) {
  const customer = await customersRepo.findByIdForUser(data.customerId, userId);
  if (!customer) throw AppError.badRequest('Customer not found for this account');

  const template = {
    items: data.items,
    taxPercent: data.taxPercent || 0,
    paymentTerms: data.paymentTerms,
    notes: data.notes,
    currency: data.currency || 'NGN',
  };

  // Created unconfirmed (confirmed_at stays NULL) — the cron job skips it until
  // the user explicitly confirms, per the PRD's recurring-billing risk mitigation.
  const schedule = await recurringRepo.create({
    userId,
    customerId: data.customerId,
    template,
    frequency: data.frequency,
    nextRunDate: data.startDate || today(),
  });

  return toPublicSchedule({ ...schedule, customer_name: customer.name, customer_email: customer.email });
}

async function listSchedules(userId, { status, page, limit }) {
  const { rows, total } = await recurringRepo.list({ userId, status, page, limit });
  return {
    schedules: rows.map(toPublicSchedule),
    pagination: { page, limit, total, totalPages: Math.max(1, Math.ceil(total / limit)) },
  };
}

async function getSchedule(userId, id) {
  const schedule = await recurringRepo.findByIdForUser(id, userId);
  if (!schedule) throw AppError.notFound('Recurring schedule not found');

  const history = await recurringRepo.getGenerationHistory(id, userId);
  return {
    ...toPublicSchedule(schedule),
    generatedInvoices: history.map((h) => ({
      invoiceId: h.invoice_id,
      invoiceNumber: h.invoice_number,
      status: h.status,
      totalAmount: h.total_amount,
      currency: h.currency,
      generatedAt: h.generated_at,
    })),
  };
}

async function updateSchedule(userId, id, data) {
  const existing = await recurringRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Recurring schedule not found');
  if (existing.status === 'cancelled') {
    throw AppError.conflict('Cancelled schedules cannot be edited');
  }

  let customer;
  if (data.customerId && data.customerId !== existing.customer_id) {
    customer = await customersRepo.findByIdForUser(data.customerId, userId);
    if (!customer) throw AppError.badRequest('Customer not found for this account');
  }

  const template = {
    items: data.items || existing.template.items,
    taxPercent: data.taxPercent !== undefined ? data.taxPercent : existing.template.taxPercent,
    paymentTerms: data.paymentTerms !== undefined ? data.paymentTerms : existing.template.paymentTerms,
    notes: data.notes !== undefined ? data.notes : existing.template.notes,
    currency: existing.template.currency,
  };

  const updated = await recurringRepo.update(id, userId, {
    customerId: data.customerId,
    template,
    frequency: data.frequency,
  });

  return toPublicSchedule({
    ...updated,
    customer_name: customer ? customer.name : existing.customer_name,
    customer_email: customer ? customer.email : existing.customer_email,
  });
}

async function confirmSchedule(userId, id) {
  const existing = await recurringRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Recurring schedule not found');
  if (existing.confirmed_at) throw AppError.conflict('This schedule is already confirmed');

  const updated = await recurringRepo.confirm(id, userId);
  return toPublicSchedule({
    ...updated,
    customer_name: existing.customer_name,
    customer_email: existing.customer_email,
  });
}

async function pauseSchedule(userId, id) {
  const existing = await recurringRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Recurring schedule not found');
  if (existing.status !== 'active') throw AppError.conflict('Only active schedules can be paused');

  const updated = await recurringRepo.updateStatus(id, userId, 'paused');
  return toPublicSchedule({
    ...updated,
    customer_name: existing.customer_name,
    customer_email: existing.customer_email,
  });
}

async function resumeSchedule(userId, id) {
  const existing = await recurringRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Recurring schedule not found');
  if (existing.status !== 'paused') throw AppError.conflict('Only paused schedules can be resumed');

  const updated = await recurringRepo.updateStatus(id, userId, 'active');
  return toPublicSchedule({
    ...updated,
    customer_name: existing.customer_name,
    customer_email: existing.customer_email,
  });
}

async function cancelSchedule(userId, id) {
  const existing = await recurringRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Recurring schedule not found');
  if (existing.status === 'cancelled') throw AppError.conflict('This schedule is already cancelled');

  const updated = await recurringRepo.updateStatus(id, userId, 'cancelled');
  return toPublicSchedule({
    ...updated,
    customer_name: existing.customer_name,
    customer_email: existing.customer_email,
  });
}

/**
 * Generates one invoice for a due schedule, logs it, and advances next_run_date.
 * Reuses invoicesService.createInvoice so numbering/watermarking/totals logic
 * lives in exactly one place.
 */
async function generateForSchedule(schedule) {
  const invoice = await invoicesService.createInvoice(schedule.user_id, {
    customerId: schedule.customer_id,
    items: schedule.template.items,
    taxPercent: schedule.template.taxPercent || 0,
    paymentTerms: schedule.template.paymentTerms,
    notes: schedule.template.notes,
    currency: schedule.template.currency || 'NGN',
    // dueDate omitted on purpose — invoicesService applies its own 14-day default from today.
  });

  await recurringRepo.logGeneratedInvoice(schedule.id, invoice.id);
  await recurringRepo.advance(schedule.id, {
    lastRunDate: schedule.next_run_date,
    nextRunDate: addInterval(schedule.next_run_date, schedule.frequency),
  });

  return invoice;
}

/**
 * Entry point for the cron job (see src/jobs/README.md): generates invoices
 * for every due, confirmed, active schedule across all users. One schedule
 * failing (e.g. its customer was deleted) doesn't stop the rest from running.
 */
async function generateAllDueInvoices() {
  const due = await recurringRepo.findDueSchedules();
  const results = [];
  for (const schedule of due) {
    try {
      // eslint-disable-next-line no-await-in-loop
      const invoice = await generateForSchedule(schedule);
      results.push({ scheduleId: schedule.id, invoiceId: invoice.id, ok: true });
    } catch (err) {
      // eslint-disable-next-line no-console
      console.error(`Failed to generate invoice for recurring schedule ${schedule.id}:`, err.message);
      results.push({ scheduleId: schedule.id, ok: false, error: err.message });
    }
  }
  return results;
}

/**
 * Same as above, scoped to one user. Lets you test the whole recurring-billing
 * loop right now via an authenticated endpoint, without waiting for a cron
 * schedule to exist.
 */
async function generateDueInvoicesForUser(userId) {
  const due = await recurringRepo.findDueSchedules({ userId });
  const results = [];
  for (const schedule of due) {
    // eslint-disable-next-line no-await-in-loop
    const invoice = await generateForSchedule(schedule);
    results.push({ scheduleId: schedule.id, invoiceId: invoice.id });
  }
  return results;
}

module.exports = {
  toPublicSchedule,
  addInterval,
  createSchedule,
  listSchedules,
  getSchedule,
  updateSchedule,
  confirmSchedule,
  pauseSchedule,
  resumeSchedule,
  cancelSchedule,
  generateForSchedule,
  generateAllDueInvoices,
  generateDueInvoicesForUser,
};

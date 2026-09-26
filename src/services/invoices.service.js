const AppError = require('../utils/AppError');
const invoicesRepo = require('../repositories/invoices.repository');
const customersRepo = require('../repositories/customers.repository');
const usersRepo = require('../repositories/users.repository');
const emailService = require('./email.service');

const DEFAULT_DUE_DAYS = 14; // per PRD: "default payment due date of 14 days"
const EDITABLE_STATUSES = ['draft'];
const DELETABLE_STATUSES = ['draft', 'cancelled'];

function round2(n) {
  return Math.round((n + Number.EPSILON) * 100) / 100;
}

/** Computes each line's total plus subtotal/tax/grand total. Pure function — no I/O. */
function computeItemsAndTotals(items, taxPercent = 0) {
  const computedItems = items.map((item) => ({
    ...item,
    lineTotal: round2(item.quantity * item.unitPrice),
  }));
  const subtotal = round2(computedItems.reduce((sum, item) => sum + item.lineTotal, 0));
  const taxAmount = round2(subtotal * (Number(taxPercent) / 100));
  const totalAmount = round2(subtotal + taxAmount);
  return { computedItems, subtotal, taxAmount, totalAmount };
}

function defaultDueDate(fromDate = new Date()) {
  const due = new Date(fromDate);
  due.setDate(due.getDate() + DEFAULT_DUE_DAYS);
  return due.toISOString().slice(0, 10); // YYYY-MM-DD, matches the `date` column type
}

/** Normalizes a DB-shaped item (snake_case, numeric-as-string) back into the shape computeItemsAndTotals expects. */
function normalizeDbItem(item) {
  return {
    description: item.description,
    quantity: Number(item.quantity),
    unitPrice: Number(item.unit_price),
  };
}

function toPublicInvoice(invoice) {
  return {
    id: invoice.id,
    invoiceNumber: invoice.invoice_number,
    status: invoice.status,
    currency: invoice.currency,
    subtotal: invoice.subtotal,
    taxAmount: invoice.tax_amount,
    totalAmount: invoice.total_amount,
    paymentTerms: invoice.payment_terms,
    notes: invoice.notes,
    issueDate: invoice.issue_date,
    dueDate: invoice.due_date,
    sentAt: invoice.sent_at,
    paidAt: invoice.paid_at,
    isWatermarked: invoice.is_watermarked,
    customerId: invoice.customer_id,
    customerName: invoice.customer_name,
    customerEmail: invoice.customer_email,
    items: (invoice.items || []).map((item) => ({
      id: item.id,
      description: item.description,
      quantity: item.quantity,
      unitPrice: item.unit_price,
      lineTotal: item.line_total,
    })),
    createdAt: invoice.created_at,
    updatedAt: invoice.updated_at,
  };
}

async function createInvoice(userId, data) {
  const customer = await customersRepo.findByIdForUser(data.customerId, userId);
  if (!customer) throw AppError.badRequest('Customer not found for this account');

  const user = await usersRepo.findById(userId);
  const { computedItems, subtotal, taxAmount, totalAmount } = computeItemsAndTotals(
    data.items,
    data.taxPercent
  );

  const invoice = await invoicesRepo.createWithItems({
    userId,
    customerId: data.customerId,
    currency: data.currency || 'NGN',
    subtotal,
    taxAmount,
    totalAmount,
    paymentTerms: data.paymentTerms,
    notes: data.notes,
    dueDate: data.dueDate || defaultDueDate(),
    isWatermarked: user.plan_type !== 'pro', // Pro plan removes the InvoicePro NG watermark
    items: computedItems,
  });

  return toPublicInvoice({
    ...invoice,
    customer_name: customer.name,
    customer_email: customer.email,
  });
}

async function listInvoices(userId, { status, search, page, limit }) {
  const { rows, total } = await invoicesRepo.list({ userId, status, search, page, limit });
  return {
    invoices: rows.map(toPublicInvoice),
    pagination: { page, limit, total, totalPages: Math.max(1, Math.ceil(total / limit)) },
  };
}

async function getInvoice(userId, id) {
  const invoice = await invoicesRepo.findByIdForUser(id, userId);
  if (!invoice) throw AppError.notFound('Invoice not found');
  return toPublicInvoice(invoice);
}

async function updateInvoice(userId, id, data) {
  const existing = await invoicesRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Invoice not found');
  if (!EDITABLE_STATUSES.includes(existing.status)) {
    throw AppError.conflict('Only draft invoices can be edited');
  }

  let customer;
  if (data.customerId && data.customerId !== existing.customer_id) {
    customer = await customersRepo.findByIdForUser(data.customerId, userId);
    if (!customer) throw AppError.badRequest('Customer not found for this account');
  }
  const customerId = data.customerId || existing.customer_id;

  // If items aren't being changed, keep the existing tax rate implied by the stored amounts.
  const impliedTaxPercent =
    existing.subtotal > 0 ? round2((existing.tax_amount / existing.subtotal) * 100) : 0;
  const taxPercent = data.taxPercent !== undefined ? data.taxPercent : impliedTaxPercent;
  const itemsInput = data.items || existing.items.map(normalizeDbItem);

  const { computedItems, subtotal, taxAmount, totalAmount } = computeItemsAndTotals(
    itemsInput,
    taxPercent
  );

  const updated = await invoicesRepo.updateWithItems({
    id,
    userId,
    customerId,
    subtotal,
    taxAmount,
    totalAmount,
    paymentTerms: data.paymentTerms !== undefined ? data.paymentTerms : existing.payment_terms,
    notes: data.notes !== undefined ? data.notes : existing.notes,
    dueDate: data.dueDate || existing.due_date,
    items: computedItems,
  });

  return toPublicInvoice({
    ...updated,
    customer_name: customer ? customer.name : existing.customer_name,
    customer_email: customer ? customer.email : existing.customer_email,
  });
}

async function deleteInvoice(userId, id) {
  const existing = await invoicesRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Invoice not found');
  if (!DELETABLE_STATUSES.includes(existing.status)) {
    throw AppError.conflict('Only draft or cancelled invoices can be deleted');
  }
  await invoicesRepo.remove(id, userId);
}

async function markAsSent(userId, id) {
  const existing = await invoicesRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Invoice not found');
  if (existing.status !== 'draft') {
    throw AppError.conflict('Only draft invoices can be sent');
  }
  if (!existing.customer_email) {
    throw AppError.badRequest('This customer has no email address on file');
  }

  const updated = await invoicesRepo.updateStatus(id, userId, {
    status: 'sent',
    sentAt: new Date(),
  });

  // Fire-and-forget, same pattern as the registration confirmation email —
  // don't fail the request if the email provider hiccups.
  emailService
    .send({
      to: existing.customer_email,
      subject: `Invoice ${existing.invoice_number}`,
      html: `<p>You have a new invoice (${existing.invoice_number}) for ${existing.currency} ${existing.total_amount}, due ${existing.due_date}.</p>`,
    })
    .catch((err) => {
      // eslint-disable-next-line no-console
      console.error('Failed to send invoice email:', err.message);
    });

  return toPublicInvoice({ ...updated, items: existing.items, customer_name: existing.customer_name, customer_email: existing.customer_email });
}

/**
 * Manual override until the Payments/Payscribe module exists — once webhooks
 * are wired up, that module should call invoicesRepo.updateStatus directly
 * instead of going through this endpoint.
 */
async function markAsPaid(userId, id) {
  const existing = await invoicesRepo.findByIdForUser(id, userId);
  if (!existing) throw AppError.notFound('Invoice not found');
  if (!['sent', 'overdue'].includes(existing.status)) {
    throw AppError.conflict('Only sent or overdue invoices can be marked as paid');
  }

  const updated = await invoicesRepo.updateStatus(id, userId, {
    status: 'paid',
    paidAt: new Date(),
  });

  return toPublicInvoice({ ...updated, items: existing.items, customer_name: existing.customer_name, customer_email: existing.customer_email });
}

module.exports = {
  toPublicInvoice,
  computeItemsAndTotals,
  defaultDueDate,
  createInvoice,
  listInvoices,
  getInvoice,
  updateInvoice,
  deleteInvoice,
  markAsSent,
  markAsPaid,
};

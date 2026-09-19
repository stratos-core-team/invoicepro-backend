const AppError = require('../utils/AppError');
const customersRepo = require('../repositories/customers.repository');

function toPublicCustomer(customer) {
  return {
    id: customer.id,
    name: customer.name,
    email: customer.email,
    phone: customer.phone,
    address: customer.address,
    source: customer.source,
    createdAt: customer.created_at,
    updatedAt: customer.updated_at,
  };
}

async function createCustomer(userId, data) {
  const customer = await customersRepo.create({ userId, ...data });
  return toPublicCustomer(customer);
}

async function listCustomers(userId, { search, page, limit }) {
  const { rows, total } = await customersRepo.list({ userId, search, page, limit });
  return {
    customers: rows.map(toPublicCustomer),
    pagination: {
      page,
      limit,
      total,
      totalPages: Math.max(1, Math.ceil(total / limit)),
    },
  };
}

async function getCustomer(userId, customerId) {
  const customer = await customersRepo.findByIdForUser(customerId, userId);
  if (!customer) throw AppError.notFound('Customer not found');
  return toPublicCustomer(customer);
}

async function updateCustomer(userId, customerId, data) {
  // Ensure it exists (and belongs to this user) before attempting the update,
  // so we can return a clean 404 instead of a silent no-op.
  const existing = await customersRepo.findByIdForUser(customerId, userId);
  if (!existing) throw AppError.notFound('Customer not found');

  const updated = await customersRepo.update(customerId, userId, data);
  return toPublicCustomer(updated);
}

async function deleteCustomer(userId, customerId) {
  const deleted = await customersRepo.remove(customerId, userId);
  if (!deleted) throw AppError.notFound('Customer not found');
}

async function getInvoiceHistory(userId, customerId) {
  // Confirms ownership first — otherwise this would leak invoice existence
  // for customer IDs belonging to other users.
  const customer = await customersRepo.findByIdForUser(customerId, userId);
  if (!customer) throw AppError.notFound('Customer not found');

  const invoices = await customersRepo.getInvoiceHistory(customerId, userId);
  return invoices.map((inv) => ({
    id: inv.id,
    invoiceNumber: inv.invoice_number,
    status: inv.status,
    currency: inv.currency,
    totalAmount: inv.total_amount,
    issueDate: inv.issue_date,
    dueDate: inv.due_date,
    paidAt: inv.paid_at,
    createdAt: inv.created_at,
  }));
}

module.exports = {
  toPublicCustomer,
  createCustomer,
  listCustomers,
  getCustomer,
  updateCustomer,
  deleteCustomer,
  getInvoiceHistory,
};

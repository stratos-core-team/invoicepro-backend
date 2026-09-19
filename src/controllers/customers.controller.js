const customersService = require('../services/customers.service');
const { ok, created } = require('../utils/apiResponse');

async function create(req, res) {
  const customer = await customersService.createCustomer(req.user.id, req.body);
  return created(res, { customer });
}

async function list(req, res) {
  const result = await customersService.listCustomers(req.user.id, req.query);
  return ok(res, { customers: result.customers }, { pagination: result.pagination });
}

async function getOne(req, res) {
  const customer = await customersService.getCustomer(req.user.id, req.params.id);
  return ok(res, { customer });
}

async function update(req, res) {
  const customer = await customersService.updateCustomer(req.user.id, req.params.id, req.body);
  return ok(res, { customer });
}

async function remove(req, res) {
  await customersService.deleteCustomer(req.user.id, req.params.id);
  return ok(res, { deleted: true });
}

async function invoiceHistory(req, res) {
  const invoices = await customersService.getInvoiceHistory(req.user.id, req.params.id);
  return ok(res, { invoices });
}

module.exports = { create, list, getOne, update, remove, invoiceHistory };

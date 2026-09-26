const invoicesService = require('../services/invoices.service');
const { ok, created } = require('../utils/apiResponse');

async function create(req, res) {
  const invoice = await invoicesService.createInvoice(req.user.id, req.body);
  return created(res, { invoice });
}

async function list(req, res) {
  const result = await invoicesService.listInvoices(req.user.id, req.query);
  return ok(res, { invoices: result.invoices }, { pagination: result.pagination });
}

async function getOne(req, res) {
  const invoice = await invoicesService.getInvoice(req.user.id, req.params.id);
  return ok(res, { invoice });
}

async function update(req, res) {
  const invoice = await invoicesService.updateInvoice(req.user.id, req.params.id, req.body);
  return ok(res, { invoice });
}

async function remove(req, res) {
  await invoicesService.deleteInvoice(req.user.id, req.params.id);
  return ok(res, { deleted: true });
}

async function send(req, res) {
  const invoice = await invoicesService.markAsSent(req.user.id, req.params.id);
  return ok(res, { invoice });
}

async function markPaid(req, res) {
  const invoice = await invoicesService.markAsPaid(req.user.id, req.params.id);
  return ok(res, { invoice });
}

module.exports = { create, list, getOne, update, remove, send, markPaid };

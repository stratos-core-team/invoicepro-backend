const service = require('../services/recurringSchedules.service');
const { ok, created } = require('../utils/apiResponse');

async function create(req, res) {
  const schedule = await service.createSchedule(req.user.id, req.body);
  return created(res, { schedule });
}

async function list(req, res) {
  const result = await service.listSchedules(req.user.id, req.query);
  return ok(res, { schedules: result.schedules }, { pagination: result.pagination });
}

async function getOne(req, res) {
  const schedule = await service.getSchedule(req.user.id, req.params.id);
  return ok(res, { schedule });
}

async function update(req, res) {
  const schedule = await service.updateSchedule(req.user.id, req.params.id, req.body);
  return ok(res, { schedule });
}

async function confirm(req, res) {
  const schedule = await service.confirmSchedule(req.user.id, req.params.id);
  return ok(res, { schedule });
}

async function pause(req, res) {
  const schedule = await service.pauseSchedule(req.user.id, req.params.id);
  return ok(res, { schedule });
}

async function resume(req, res) {
  const schedule = await service.resumeSchedule(req.user.id, req.params.id);
  return ok(res, { schedule });
}

async function cancel(req, res) {
  const schedule = await service.cancelSchedule(req.user.id, req.params.id);
  return ok(res, { schedule });
}

/** Dev/testing helper — real deployments generate due invoices via the cron job instead. */
async function runDue(req, res) {
  const generated = await service.generateDueInvoicesForUser(req.user.id);
  return ok(res, { generated });
}

module.exports = { create, list, getOne, update, confirm, pause, resume, cancel, runDue };

const dashboardService = require('../services/dashboard.service');
const { ok } = require('../utils/apiResponse');

async function getDashboard(req, res) {
  const data = await dashboardService.getDashboard(req.user.id);
  return ok(res, data);
}

module.exports = { getDashboard };

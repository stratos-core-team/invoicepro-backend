const authService = require('./auth.service');

async function register(req, res) {
  const result = await authService.registerUser(req.body);
  res.status(201).json({ success: true, data: result });
}

async function login(req, res) {
  const result = await authService.loginUser(req.body);
  res.status(200).json({ success: true, data: result });
}

async function refresh(req, res) {
  const result = await authService.refreshAccessToken(req.body.refreshToken);
  res.status(200).json({ success: true, data: result });
}

async function logout(req, res) {
  await authService.logoutUser(req.body.refreshToken);
  res.status(200).json({ success: true, message: 'Logged out' });
}

module.exports = { register, login, refresh, logout };

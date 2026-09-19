const Joi = require('joi');

const registerSchema = Joi.object({
  fullName: Joi.string().min(2).max(150).required(),
  businessName: Joi.string().min(2).max(150).required(),
  email: Joi.string().email().required(),
  password: Joi.string().min(8).max(72).required(),
});

const loginSchema = Joi.object({
  email: Joi.string().email().required(),
  password: Joi.string().required(),
  totpCode: Joi.string().length(6).pattern(/^\d+$/).optional(),
});

const refreshSchema = Joi.object({
  refreshToken: Joi.string().required(),
});

module.exports = { registerSchema, loginSchema, refreshSchema };

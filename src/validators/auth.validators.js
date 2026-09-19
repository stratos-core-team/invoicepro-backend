const { z } = require('zod');

const registerSchema = z.object({
  fullName: z.string().trim().min(2, 'Full name is too short').max(150),
  businessName: z.string().trim().min(2, 'Business name is too short').max(150),
  email: z.string().trim().email('Invalid email address'),
  password: z.string().min(8, 'Password must be at least 8 characters').max(72),
});

const loginSchema = z.object({
  email: z.string().trim().email('Invalid email address'),
  password: z.string().min(1, 'Password is required'),
});

const profileSetupSchema = z.object({
  businessName: z.string().trim().min(2).max(150).optional(),
  contactPhone: z.string().trim().min(7).max(30).optional(),
  contactAddress: z.string().trim().max(500).optional(),
  businessLogoUrl: z.string().trim().url('Must be a valid URL').optional(),
});

module.exports = { registerSchema, loginSchema, profileSetupSchema };

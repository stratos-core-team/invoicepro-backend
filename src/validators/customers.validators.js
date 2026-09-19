const { z } = require('zod');

const createCustomerSchema = z.object({
  name: z.string().trim().min(2, 'Name is too short').max(150),
  email: z.string().trim().email('Invalid email address').optional().or(z.literal('')),
  phone: z.string().trim().min(7).max(30).optional().or(z.literal('')),
  address: z.string().trim().max(500).optional(),
  // 'contacts' when added via the phone-contacts picker on the client; defaults to manual entry
  source: z.enum(['manual', 'contacts']).optional(),
});

const updateCustomerSchema = z.object({
  name: z.string().trim().min(2).max(150).optional(),
  email: z.string().trim().email('Invalid email address').optional().or(z.literal('')),
  phone: z.string().trim().min(7).max(30).optional().or(z.literal('')),
  address: z.string().trim().max(500).optional(),
});

const idParamSchema = z.object({
  id: z.string().uuid('Invalid customer id'),
});

const listQuerySchema = z.object({
  search: z.string().trim().max(150).optional(),
  page: z.coerce.number().int().min(1).default(1),
  limit: z.coerce.number().int().min(1).max(100).default(20),
});

module.exports = {
  createCustomerSchema,
  updateCustomerSchema,
  idParamSchema,
  listQuerySchema,
};

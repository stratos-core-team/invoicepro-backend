const { z } = require('zod');
const { lineItemSchema } = require('./invoices.validators');

const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

const createScheduleSchema = z.object({
  customerId: z.string().uuid('Invalid customer id'),
  items: z.array(lineItemSchema).min(1, 'At least one line item is required'),
  frequency: z.enum(FREQUENCIES),
  // First invoice date. Omit to start today (invoice still won't generate until confirmed — see confirm endpoint).
  startDate: z.string().date('startDate must be YYYY-MM-DD').optional(),
  paymentTerms: z.string().trim().max(1000).optional(),
  notes: z.string().trim().max(2000).optional(),
  taxPercent: z.coerce.number().min(0).max(100).optional().default(0),
  currency: z.string().length(3).optional().default('NGN'),
});

const updateScheduleSchema = z.object({
  customerId: z.string().uuid('Invalid customer id').optional(),
  items: z.array(lineItemSchema).min(1, 'At least one line item is required').optional(),
  frequency: z.enum(FREQUENCIES).optional(),
  paymentTerms: z.string().trim().max(1000).optional(),
  notes: z.string().trim().max(2000).optional(),
  taxPercent: z.coerce.number().min(0).max(100).optional(),
});

const listQuerySchema = z.object({
  status: z.enum(['active', 'paused', 'cancelled']).optional(),
  page: z.coerce.number().int().min(1).default(1),
  limit: z.coerce.number().int().min(1).max(100).default(20),
});

const idParamSchema = z.object({
  id: z.string().uuid('Invalid schedule id'),
});

module.exports = { createScheduleSchema, updateScheduleSchema, listQuerySchema, idParamSchema };

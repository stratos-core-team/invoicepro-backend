const { z } = require('zod');

const lineItemSchema = z.object({
  description: z.string().trim().min(1, 'Line item description is required').max(500),
  quantity: z.coerce.number().positive('Quantity must be greater than 0').default(1),
  unitPrice: z.coerce.number().nonnegative('Unit price cannot be negative'),
});

const createInvoiceSchema = z.object({
  customerId: z.string().uuid('Invalid customer id'),
  items: z.array(lineItemSchema).min(1, 'At least one line item is required'),
  // ISO date string "YYYY-MM-DD" — omit to fall back to the 14-day PRD default
  dueDate: z.string().date('dueDate must be YYYY-MM-DD').optional(),
  paymentTerms: z.string().trim().max(1000).optional(),
  notes: z.string().trim().max(2000).optional(),
  taxPercent: z.coerce.number().min(0).max(100).optional().default(0),
  currency: z.string().length(3).optional().default('NGN'),
});

const updateInvoiceSchema = z.object({
  customerId: z.string().uuid('Invalid customer id').optional(),
  items: z.array(lineItemSchema).min(1, 'At least one line item is required').optional(),
  dueDate: z.string().date('dueDate must be YYYY-MM-DD').optional(),
  paymentTerms: z.string().trim().max(1000).optional(),
  notes: z.string().trim().max(2000).optional(),
  taxPercent: z.coerce.number().min(0).max(100).optional(),
});

const listQuerySchema = z.object({
  status: z.enum(['draft', 'sent', 'paid', 'overdue', 'cancelled']).optional(),
  search: z.string().trim().max(150).optional(),
  page: z.coerce.number().int().min(1).default(1),
  limit: z.coerce.number().int().min(1).max(100).default(20),
});

const idParamSchema = z.object({
  id: z.string().uuid('Invalid invoice id'),
});

module.exports = {
  lineItemSchema,
  createInvoiceSchema,
  updateInvoiceSchema,
  listQuerySchema,
  idParamSchema,
};

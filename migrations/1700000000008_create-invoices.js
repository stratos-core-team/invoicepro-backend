exports.up = (pgm) => {
  pgm.createTable('invoices', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    user_id: {
      type: 'uuid',
      notNull: true,
      references: 'users',
      onDelete: 'CASCADE',
    },
    customer_id: {
      type: 'uuid',
      notNull: true,
      references: 'customers',
      onDelete: 'RESTRICT',
    },
    recurring_schedule_id: {
      type: 'uuid',
      references: 'recurring_schedules',
      onDelete: 'SET NULL',
    },

    // Human-facing ID, unique per user (e.g. "INV-0001"), not the primary key
    invoice_number: { type: 'varchar(30)', notNull: true },

    status: { type: 'varchar(20)', notNull: true, default: 'draft' },
    // 'draft' | 'sent' | 'paid' | 'overdue' | 'cancelled'

    currency: { type: 'varchar(3)', notNull: true, default: 'NGN' },
    subtotal: { type: 'numeric(14,2)', notNull: true, default: 0 },
    tax_amount: { type: 'numeric(14,2)', notNull: true, default: 0 },
    total_amount: { type: 'numeric(14,2)', notNull: true, default: 0 },

    payment_terms: { type: 'text' },
    notes: { type: 'text' },

    issue_date: { type: 'date', notNull: true, default: pgm.func('current_date') },
    due_date: { type: 'date', notNull: true },

    sent_at: { type: 'timestamptz' },
    paid_at: { type: 'timestamptz' },

    // Free plan invoices are watermarked; snapshot at creation time in case plan changes later
    is_watermarked: { type: 'boolean', notNull: true, default: true },

    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
    updated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.addConstraint('invoices', 'invoices_status_check', {
    check: "status IN ('draft', 'sent', 'paid', 'overdue', 'cancelled')",
  });
  pgm.addConstraint('invoices', 'invoices_user_invoice_number_unique', {
    unique: ['user_id', 'invoice_number'],
  });

  pgm.createIndex('invoices', 'user_id');
  pgm.createIndex('invoices', 'customer_id');
  pgm.createIndex('invoices', ['user_id', 'status']);
  pgm.createIndex('invoices', 'due_date');
};

exports.down = (pgm) => {
  pgm.dropTable('invoices');
};

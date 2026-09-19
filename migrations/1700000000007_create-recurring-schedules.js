exports.up = (pgm) => {
  pgm.createTable('recurring_schedules', {
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
      onDelete: 'CASCADE',
    },
    // Snapshot of line items / notes / payment terms used to generate each invoice.
    // Shape: [{ description, quantity, unit_price }]
    template: { type: 'jsonb', notNull: true },
    frequency: { type: 'varchar(20)', notNull: true }, // 'weekly' | 'monthly' | 'quarterly' | 'yearly'
    status: { type: 'varchar(20)', notNull: true, default: 'active' }, // 'active' | 'paused' | 'cancelled'
    next_run_date: { type: 'date', notNull: true },
    last_run_date: { type: 'date' },
    // Confirmation step before activation, per PRD risk mitigation
    confirmed_at: { type: 'timestamptz' },
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
    updated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.addConstraint('recurring_schedules', 'recurring_schedules_frequency_check', {
    check: "frequency IN ('weekly', 'monthly', 'quarterly', 'yearly')",
  });
  pgm.addConstraint('recurring_schedules', 'recurring_schedules_status_check', {
    check: "status IN ('active', 'paused', 'cancelled')",
  });

  pgm.createIndex('recurring_schedules', 'user_id');
  pgm.createIndex('recurring_schedules', ['status', 'next_run_date']);
};

exports.down = (pgm) => {
  pgm.dropTable('recurring_schedules');
};

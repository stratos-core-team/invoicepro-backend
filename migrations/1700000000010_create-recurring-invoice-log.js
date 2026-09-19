exports.up = (pgm) => {
  pgm.createTable('recurring_invoice_log', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    recurring_schedule_id: {
      type: 'uuid',
      notNull: true,
      references: 'recurring_schedules',
      onDelete: 'CASCADE',
    },
    invoice_id: {
      type: 'uuid',
      notNull: true,
      references: 'invoices',
      onDelete: 'CASCADE',
    },
    generated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.createIndex('recurring_invoice_log', 'recurring_schedule_id');
};

exports.down = (pgm) => {
  pgm.dropTable('recurring_invoice_log');
};

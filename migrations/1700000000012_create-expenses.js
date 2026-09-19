exports.up = (pgm) => {
  pgm.createTable('expenses', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    user_id: {
      type: 'uuid',
      notNull: true,
      references: 'users',
      onDelete: 'CASCADE',
    },
    // Optional link to the project/invoice this expense relates to
    invoice_id: {
      type: 'uuid',
      references: 'invoices',
      onDelete: 'SET NULL',
    },
    tax_rate_id: {
      type: 'uuid',
      references: 'tax_rates',
      onDelete: 'SET NULL',
    },
    description: { type: 'text', notNull: true },
    category: { type: 'varchar(50)' },
    amount: { type: 'numeric(14,2)', notNull: true },
    tax_amount: { type: 'numeric(14,2)', notNull: true, default: 0 },
    incurred_date: { type: 'date', notNull: true, default: pgm.func('current_date') },
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
    updated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.createIndex('expenses', 'user_id');
  pgm.createIndex('expenses', ['user_id', 'incurred_date']);
};

exports.down = (pgm) => {
  pgm.dropTable('expenses');
};

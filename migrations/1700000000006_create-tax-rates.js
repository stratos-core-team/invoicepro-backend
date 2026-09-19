exports.up = (pgm) => {
  pgm.createTable('tax_rates', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    user_id: {
      type: 'uuid',
      notNull: true,
      references: 'users',
      onDelete: 'CASCADE',
    },
    name: { type: 'varchar(50)', notNull: true }, // e.g. 'VAT', 'GST'
    rate_percent: { type: 'numeric(5,2)', notNull: true }, // e.g. 7.50
    is_default: { type: 'boolean', notNull: true, default: false },
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
    updated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.createIndex('tax_rates', 'user_id');
};

exports.down = (pgm) => {
  pgm.dropTable('tax_rates');
};

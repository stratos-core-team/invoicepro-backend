exports.up = (pgm) => {
  pgm.createTable('invoice_items', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    invoice_id: {
      type: 'uuid',
      notNull: true,
      references: 'invoices',
      onDelete: 'CASCADE',
    },
    description: { type: 'text', notNull: true },
    quantity: { type: 'numeric(10,2)', notNull: true, default: 1 },
    unit_price: { type: 'numeric(14,2)', notNull: true },
    // Stored (not just computed) so historical invoices stay accurate
    line_total: { type: 'numeric(14,2)', notNull: true },
    position: { type: 'integer', notNull: true, default: 0 }, // display order
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.createIndex('invoice_items', 'invoice_id');
};

exports.down = (pgm) => {
  pgm.dropTable('invoice_items');
};

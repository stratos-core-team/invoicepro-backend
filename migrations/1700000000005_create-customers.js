exports.up = (pgm) => {
  pgm.createTable('customers', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    user_id: {
      type: 'uuid',
      notNull: true,
      references: 'users',
      onDelete: 'CASCADE',
    },
    name: { type: 'varchar(150)', notNull: true },
    email: { type: 'varchar(255)' },
    phone: { type: 'varchar(30)' },
    address: { type: 'text' },
    // Set when the customer was created via "Add from phone contacts"
    source: { type: 'varchar(20)', notNull: true, default: 'manual' }, // 'manual' | 'contacts'
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
    updated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.addConstraint('customers', 'customers_source_check', {
    check: "source IN ('manual', 'contacts')",
  });

  pgm.createIndex('customers', 'user_id');
  pgm.createIndex('customers', ['user_id', 'name']);
};

exports.down = (pgm) => {
  pgm.dropTable('customers');
};

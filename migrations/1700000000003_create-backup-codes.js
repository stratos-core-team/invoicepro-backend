exports.up = (pgm) => {
  pgm.createTable('backup_codes', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    user_id: {
      type: 'uuid',
      notNull: true,
      references: 'users',
      onDelete: 'CASCADE',
    },
    code_hash: { type: 'text', notNull: true },
    used_at: { type: 'timestamptz' },
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.createIndex('backup_codes', 'user_id');
};

exports.down = (pgm) => {
  pgm.dropTable('backup_codes');
};

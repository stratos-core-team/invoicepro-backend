exports.up = (pgm) => {
  pgm.createTable('payments', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    invoice_id: {
      type: 'uuid',
      notNull: true,
      references: 'invoices',
      onDelete: 'CASCADE',
    },
    provider: { type: 'varchar(30)', notNull: true, default: 'payscribe' },
    provider_reference: { type: 'varchar(150)' }, // Payscribe's transaction reference
    idempotency_key: { type: 'varchar(150)' }, // dedupes retried webhooks
    amount: { type: 'numeric(14,2)', notNull: true },
    currency: { type: 'varchar(3)', notNull: true, default: 'NGN' },
    method: { type: 'varchar(30)' }, // 'bank_transfer' | 'card' | 'checkout' etc.
    status: { type: 'varchar(20)', notNull: true, default: 'pending' },
    // 'pending' | 'confirmed' | 'failed'
    raw_webhook_payload: { type: 'jsonb' },
    confirmed_at: { type: 'timestamptz' },
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
    updated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.addConstraint('payments', 'payments_status_check', {
    check: "status IN ('pending', 'confirmed', 'failed')",
  });
  pgm.addConstraint('payments', 'payments_idempotency_key_unique', {
    unique: ['provider', 'idempotency_key'],
  });

  pgm.createIndex('payments', 'invoice_id');
  pgm.createIndex('payments', 'provider_reference');
};

exports.down = (pgm) => {
  pgm.dropTable('payments');
};

exports.up = (pgm) => {
  pgm.createTable('users', {
    id: { type: 'uuid', primaryKey: true, default: pgm.func('gen_random_uuid()') },
    full_name: { type: 'varchar(150)', notNull: true },
    email: { type: 'varchar(255)', notNull: true, unique: true },
    password_hash: { type: 'text', notNull: true },

    // Business / profile setup
    business_name: { type: 'varchar(150)', notNull: true },
    business_logo_url: { type: 'text' },
    contact_phone: { type: 'varchar(30)' },
    contact_address: { type: 'text' },
    profile_completed_at: { type: 'timestamptz' },
    onboarding_completed_at: { type: 'timestamptz' },

    // Plan / billing
    plan_type: { type: 'varchar(10)', notNull: true, default: 'free' }, // 'free' | 'pro'
    plan_renews_at: { type: 'timestamptz' },

    // 2FA (TOTP)
    totp_secret: { type: 'text' },
    totp_enabled: { type: 'boolean', notNull: true, default: false },

    email_verified_at: { type: 'timestamptz' },
    created_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
    updated_at: { type: 'timestamptz', notNull: true, default: pgm.func('now()') },
  });

  pgm.addConstraint('users', 'users_plan_type_check', {
    check: "plan_type IN ('free', 'pro')",
  });

  pgm.createIndex('users', 'email');
};

exports.down = (pgm) => {
  pgm.dropTable('users');
};

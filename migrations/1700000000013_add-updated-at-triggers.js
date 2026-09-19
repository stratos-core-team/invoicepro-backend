const TABLES_WITH_UPDATED_AT = [
  'users',
  'customers',
  'tax_rates',
  'recurring_schedules',
  'invoices',
  'payments',
  'expenses',
];

exports.up = (pgm) => {
  pgm.createFunction(
    'set_updated_at',
    [],
    { returns: 'trigger', language: 'plpgsql' },
    `
    BEGIN
      NEW.updated_at = now();
      RETURN NEW;
    END;
    `
  );

  for (const table of TABLES_WITH_UPDATED_AT) {
    pgm.createTrigger(table, `${table}_set_updated_at`, {
      when: 'BEFORE',
      operation: 'UPDATE',
      function: 'set_updated_at',
      level: 'ROW',
    });
  }
};

exports.down = (pgm) => {
  for (const table of TABLES_WITH_UPDATED_AT) {
    pgm.dropTrigger(table, `${table}_set_updated_at`, { ifExists: true });
  }
  pgm.dropFunction('set_updated_at', [], { ifExists: true });
};

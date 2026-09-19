const { registerSchema, loginSchema } = require('../validators/auth.validators');

describe('registerSchema', () => {
  it('accepts a valid payload', () => {
    const result = registerSchema.safeParse({
      fullName: 'Ada Lovelace',
      businessName: 'Ada Designs',
      email: 'ada@example.com',
      password: 'supersecret123',
    });
    expect(result.success).toBe(true);
  });

  it('rejects a short password', () => {
    const result = registerSchema.safeParse({
      fullName: 'Ada Lovelace',
      businessName: 'Ada Designs',
      email: 'ada@example.com',
      password: 'short',
    });
    expect(result.success).toBe(false);
  });

  it('rejects an invalid email', () => {
    const result = registerSchema.safeParse({
      fullName: 'Ada Lovelace',
      businessName: 'Ada Designs',
      email: 'not-an-email',
      password: 'supersecret123',
    });
    expect(result.success).toBe(false);
  });
});

describe('loginSchema', () => {
  it('requires both email and password', () => {
    expect(loginSchema.safeParse({ email: 'a@b.com', password: 'x' }).success).toBe(true);
    expect(loginSchema.safeParse({ email: 'a@b.com' }).success).toBe(false);
    expect(loginSchema.safeParse({ password: 'x' }).success).toBe(false);
  });
});

require('dotenv').config();

/**
 * Centralized, validated access to environment variables.
 * Import this instead of touching process.env directly elsewhere in the app.
 */
function required(name, fallback) {
  const value = process.env[name] ?? fallback;
  if (value === undefined) {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

const env = {
  nodeEnv: process.env.NODE_ENV || 'development',
  isProd: process.env.NODE_ENV === 'production',
  port: Number(process.env.PORT || 4000),
  clientUrl: process.env.CLIENT_URL || 'http://localhost:5173',

  db: {
    connectionString: required('DATABASE_URL'),
    ssl: process.env.PGSSL === 'true' ? { rejectUnauthorized: false } : false,
  },

  jwt: {
    accessSecret: required('JWT_ACCESS_SECRET'),
    refreshSecret: required('JWT_REFRESH_SECRET'),
    accessExpiresIn: process.env.JWT_ACCESS_EXPIRES_IN || '15m',
    refreshExpiresIn: process.env.JWT_REFRESH_EXPIRES_IN || '30d',
  },

  email: {
    provider: process.env.EMAIL_PROVIDER || 'resend',
    resendApiKey: process.env.RESEND_API_KEY || '',
    from: process.env.EMAIL_FROM || 'InvoicePro NG <no-reply@invoicepro.ng>',
  },

  payscribe: {
    baseUrl: process.env.PAYSCRIBE_BASE_URL || 'https://api.payscribe.ng',
    secretKey: process.env.PAYSCRIBE_SECRET_KEY || '',
    publicKey: process.env.PAYSCRIBE_PUBLIC_KEY || '',
    webhookSecret: process.env.PAYSCRIBE_WEBHOOK_SECRET || '',
  },

  totp: {
    issuer: process.env.TOTP_ISSUER || 'InvoicePro NG',
  },

  rateLimit: {
    windowMs: Number(process.env.RATE_LIMIT_WINDOW_MS || 15 * 60 * 1000),
    max: Number(process.env.RATE_LIMIT_MAX || 200),
  },

  posthog: {
    apiKey: process.env.POSTHOG_API_KEY || '',
    host: process.env.POSTHOG_HOST || 'https://app.posthog.com',
  },
};

module.exports = env;

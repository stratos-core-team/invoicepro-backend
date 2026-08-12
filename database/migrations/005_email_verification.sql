ALTER TABLE users
ADD COLUMN email_verified_at DATETIME NULL AFTER email,
ADD COLUMN email_verification_token VARCHAR(128) NULL AFTER email_verified_at,
ADD COLUMN email_verification_expires_at DATETIME NULL AFTER email_verification_token;

CREATE UNIQUE INDEX uq_users_email_verification_token
ON users(email_verification_token);

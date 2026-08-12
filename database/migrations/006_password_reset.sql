ALTER TABLE users
ADD COLUMN password_reset_token VARCHAR(128) NULL AFTER password_hash,
ADD COLUMN password_reset_expires_at DATETIME NULL AFTER password_reset_token;

CREATE UNIQUE INDEX uq_users_password_reset_token
ON users(password_reset_token);

ALTER TABLE users
ADD COLUMN two_factor_enabled TINYINT(1)
    NOT NULL DEFAULT 0,
ADD COLUMN two_factor_secret VARCHAR(255) NULL;

CREATE TABLE two_factor_recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    code_hash VARCHAR(64) NOT NULL,

    used_at DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_2fa_recovery_user (
        user_id
    ),

    UNIQUE KEY uq_2fa_recovery_code (
        user_id,
        code_hash
    ),

    CONSTRAINT fk_2fa_recovery_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

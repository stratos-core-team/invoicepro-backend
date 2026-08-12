ALTER TABLE users
ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN two_factor_secret VARCHAR(255) NULL;

CREATE TABLE two_factor_recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(64) NOT NULL,

    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_2fa_recovery_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_2fa_recovery_user (user_id),

    UNIQUE KEY uq_2fa_recovery_code (
        user_id,
        code_hash
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

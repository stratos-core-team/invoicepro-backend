CREATE TABLE subscription_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    subscription_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    reference VARCHAR(100) NOT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'payscribe',
    provider_reference VARCHAR(150) NULL,

    amount DECIMAL(14,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'NGN',

    status ENUM(
        'pending',
        'successful',
        'failed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    paid_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_subscription_payment_reference (reference),

    INDEX idx_subscription_payment_user (user_id),
    INDEX idx_subscription_payment_subscription (subscription_id),

    CONSTRAINT fk_subscription_payment_subscription
        FOREIGN KEY (subscription_id)
        REFERENCES subscriptions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_subscription_payment_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

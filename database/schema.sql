-- =========================================================
-- InvoicePro Backend
-- Clean Database Schema
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- =========================================================
-- USERS
-- =========================================================

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    full_name VARCHAR(120) NOT NULL,
    business_name VARCHAR(160) NOT NULL,

    email VARCHAR(190) NOT NULL UNIQUE,

    password_hash VARCHAR(255) NOT NULL,

    token_version INT UNSIGNED NOT NULL DEFAULT 1,

    email_verified_at DATETIME NULL,
    email_verification_token VARCHAR(64) NULL,
    email_verification_expires_at DATETIME NULL,

    password_reset_token VARCHAR(64) NULL,
    password_reset_expires_at DATETIME NULL,

    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    two_factor_secret VARCHAR(255) NULL,

    plan ENUM('free', 'pro')
        NOT NULL DEFAULT 'free',

    status ENUM('active', 'suspended')
        NOT NULL DEFAULT 'active',

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_users_email_verification_token (
        email_verification_token
    ),

    INDEX idx_users_password_reset_token (
        password_reset_token
    )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- BUSINESS PROFILES
-- =========================================================

CREATE TABLE business_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    business_name VARCHAR(160) NOT NULL,

    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address TEXT NULL,

    logo VARCHAR(255) NULL,

    tax_number VARCHAR(100) NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_business_profile_user (
        user_id
    ),

    CONSTRAINT fk_business_profile_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- CUSTOMERS
-- =========================================================

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    name VARCHAR(160) NOT NULL,

    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    address TEXT NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customers_user (
        user_id
    ),

    CONSTRAINT fk_customer_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- RECURRING INVOICES
-- =========================================================

CREATE TABLE recurring_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,

    frequency ENUM(
        'weekly',
        'monthly',
        'yearly'
    ) NOT NULL,

    tax_rate DECIMAL(7,2)
        NOT NULL DEFAULT 0,

    notes TEXT NULL,

    next_run_date DATE NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,

    active TINYINT(1)
        NOT NULL DEFAULT 1,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_recurring_user (
        user_id
    ),

    INDEX idx_recurring_customer (
        customer_id
    ),

    INDEX idx_recurring_next_run (
        active,
        next_run_date
    ),

    CONSTRAINT fk_recurring_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_recurring_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- RECURRING INVOICE ITEMS
-- =========================================================

CREATE TABLE recurring_invoice_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    recurring_invoice_id BIGINT UNSIGNED NOT NULL,

    description VARCHAR(255) NOT NULL,

    quantity DECIMAL(12,2)
        NOT NULL DEFAULT 1,

    unit_price DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_recurring_item_invoice (
        recurring_invoice_id
    ),

    CONSTRAINT fk_recurring_item
        FOREIGN KEY (recurring_invoice_id)
        REFERENCES recurring_invoices(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- INVOICES
-- =========================================================

CREATE TABLE invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,

    recurring_invoice_id BIGINT UNSIGNED NULL,

    invoice_number VARCHAR(50) NOT NULL,

    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,

    subtotal DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    tax_rate DECIMAL(7,2)
        NOT NULL DEFAULT 0,

    tax_amount DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    total DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    amount_paid DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    status ENUM(
        'unpaid',
        'paid',
        'cancelled'
    ) NOT NULL DEFAULT 'unpaid',

    notes TEXT NULL,

    public_token VARCHAR(64)
        NOT NULL UNIQUE,

    paid_at DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_invoice_number (
        invoice_number
    ),

    INDEX idx_invoices_user (
        user_id
    ),

    INDEX idx_invoices_customer (
        customer_id
    ),

    INDEX idx_invoices_recurring (
        recurring_invoice_id
    ),

    INDEX idx_invoices_status (
        status
    ),

    INDEX idx_invoices_due_date (
        due_date
    ),

    CONSTRAINT fk_invoice_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_invoice_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_invoice_recurring
        FOREIGN KEY (recurring_invoice_id)
        REFERENCES recurring_invoices(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- INVOICE ITEMS
-- =========================================================

CREATE TABLE invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    invoice_id INT UNSIGNED NOT NULL,

    description VARCHAR(255) NOT NULL,

    quantity DECIMAL(12,2)
        NOT NULL,

    unit_price DECIMAL(14,2)
        NOT NULL,

    line_total DECIMAL(14,2)
        NOT NULL,

    INDEX idx_invoice_items_invoice (
        invoice_id
    ),

    CONSTRAINT fk_invoice_item_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- PAYMENTS
-- =========================================================

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    invoice_id INT UNSIGNED NOT NULL,

    reference VARCHAR(120) NULL,

    provider VARCHAR(50) NULL,

    amount DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    method VARCHAR(50) NULL,

    status ENUM(
        'pending',
        'successful',
        'failed'
    ) NOT NULL DEFAULT 'pending',

    provider_reference VARCHAR(150) NULL,

    paid_at DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_payments_invoice (
        invoice_id
    ),

    INDEX idx_payments_status (
        status
    ),

    CONSTRAINT fk_payment_invoice
        FOREIGN KEY (invoice_id)
        REFERENCES invoices(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- EXPENSES
-- =========================================================

CREATE TABLE expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    title VARCHAR(160) NOT NULL,

    description TEXT NULL,

    amount DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    tax_rate DECIMAL(7,2)
        NOT NULL DEFAULT 0,

    expense_date DATE NOT NULL,

    category VARCHAR(100) NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_expenses_user (
        user_id
    ),

    INDEX idx_expenses_date (
        expense_date
    ),

    CONSTRAINT fk_expense_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- SUBSCRIPTIONS
-- =========================================================

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    plan ENUM(
        'free',
        'pro'
    ) NOT NULL DEFAULT 'free',

    billing_cycle ENUM(
        'monthly',
        'yearly'
    ) NULL,

    status ENUM(
        'pending',
        'active',
        'cancelled',
        'expired'
    ) NOT NULL DEFAULT 'pending',

    starts_at DATETIME NULL,
    expires_at DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_subscriptions_user (
        user_id
    ),

    INDEX idx_subscriptions_status (
        status
    ),

    INDEX idx_subscriptions_expiry (
        expires_at
    ),

    CONSTRAINT fk_subscription_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- SUBSCRIPTION PAYMENTS
-- =========================================================

CREATE TABLE subscription_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    subscription_id BIGINT UNSIGNED NULL,

    reference VARCHAR(120) NOT NULL,

    provider VARCHAR(50) NULL,

    provider_reference VARCHAR(150) NULL,

    amount DECIMAL(14,2)
        NOT NULL DEFAULT 0,

    currency VARCHAR(10)
        NOT NULL DEFAULT 'NGN',

    status ENUM(
    'pending',
    'successful',
    'failed',
    'cancelled'
) NOT NULL DEFAULT 'pending',

    paid_at DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_subscription_payment_user (
        user_id
    ),

    INDEX idx_subscription_payment_subscription (
        subscription_id
    ),

    INDEX idx_subscription_payment_status (
        status
    ),
UNIQUE KEY uq_subscription_payment_reference (
    reference
),
    CONSTRAINT fk_subscription_payment_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_subscription_payment_subscription
        FOREIGN KEY (subscription_id)
        REFERENCES subscriptions(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- NOTIFICATIONS
-- =========================================================

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    type VARCHAR(50) NOT NULL,

    title VARCHAR(160) NOT NULL,

    message TEXT NOT NULL,

    read_at DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_notifications_user (
        user_id
    ),

    INDEX idx_notifications_read (
        user_id,
        read_at
    ),

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- TWO FACTOR RECOVERY CODES
-- =========================================================

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


-- =========================================================
-- WEBHOOK EVENTS
-- =========================================================

CREATE TABLE webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    provider VARCHAR(50) NOT NULL,

    event_id VARCHAR(190) NOT NULL,

    payload LONGTEXT NOT NULL,

    processed TINYINT(1)
        NOT NULL DEFAULT 0,

    processed_at DATETIME NULL,

    created_at TIMESTAMP
        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_webhook_event (
        event_id
    ),

    INDEX idx_webhook_processed (
        processed
    ),

    INDEX idx_webhook_provider (
        provider
    )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

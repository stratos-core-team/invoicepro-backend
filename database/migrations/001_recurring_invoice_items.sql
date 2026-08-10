ALTER TABLE recurring_invoices
ADD COLUMN tax_rate DECIMAL(7,2) NOT NULL DEFAULT 0 AFTER frequency,
ADD COLUMN notes TEXT NULL AFTER tax_rate;

CREATE TABLE recurring_invoice_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recurring_invoice_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_recurring_item
        FOREIGN KEY (recurring_invoice_id)
        REFERENCES recurring_invoices(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

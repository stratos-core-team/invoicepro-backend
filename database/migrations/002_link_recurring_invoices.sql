ALTER TABLE invoices
ADD COLUMN recurring_invoice_id BIGINT UNSIGNED NULL AFTER customer_id;

ALTER TABLE invoices
ADD CONSTRAINT fk_invoice_recurring
FOREIGN KEY (recurring_invoice_id)
REFERENCES recurring_invoices(id)
ON DELETE SET NULL;

CREATE INDEX idx_invoices_recurring_invoice_id
ON invoices(recurring_invoice_id);

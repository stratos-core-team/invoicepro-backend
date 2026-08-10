ALTER TABLE subscriptions
MODIFY COLUMN status
ENUM(
    'pending',
    'active',
    'cancelled',
    'expired'
)
NOT NULL DEFAULT 'pending';

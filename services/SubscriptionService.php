<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SubscriptionService
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Activate a subscription only after its payment
     * has been independently verified.
     */
    public function activateByPaymentReference(
        string $reference,
        ?string $providerReference = null
    ): array {

        $reference = trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException(
                'Payment reference is required.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find pending payment and subscription
        |--------------------------------------------------------------------------
        */

        $stmt = $this->db->prepare("
            SELECT
                sp.id AS payment_id,
                sp.subscription_id,
                sp.user_id,
                sp.reference,
                sp.amount,
                sp.currency,
                sp.status AS payment_status,

                s.plan,
                s.billing_cycle,
                s.status AS subscription_status

            FROM subscription_payments sp

            INNER JOIN subscriptions s
                ON s.id = sp.subscription_id

            WHERE sp.reference = ?

            LIMIT 1
        ");

        $stmt->bind_param(
            's',
            $reference
        );

        $stmt->execute();

        $record = $stmt
            ->get_result()
            ->fetch_assoc();

        if (!$record) {
            throw new RuntimeException(
                'Subscription payment not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Idempotency
        |--------------------------------------------------------------------------
        */

        if (
            $record['payment_status'] === 'successful'
            && $record['subscription_status'] === 'active'
        ) {

            return [
                'already_active' => true,

                'payment_id' =>
                    (int)$record['payment_id'],

                'subscription_id' =>
                    (int)$record['subscription_id'],

                'user_id' =>
                    (int)$record['user_id'],

                'plan' =>
                    $record['plan']
            ];
        }

        if ($record['payment_status'] !== 'pending') {
            throw new RuntimeException(
                'Subscription payment is not pending.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Determine subscription dates
        |--------------------------------------------------------------------------
        */

        $startsAt =
            new DateTimeImmutable('now');

        $billingCycle =
            (string)$record['billing_cycle'];

        $expiresAt = match ($billingCycle) {

            'monthly' =>
                $startsAt->modify('+1 month'),

            'yearly' =>
                $startsAt->modify('+1 year'),

            default =>
                throw new RuntimeException(
                    'Invalid subscription billing cycle.'
                )
        };

        $startsAtString =
            $startsAt->format('Y-m-d H:i:s');

        $expiresAtString =
            $expiresAt->format('Y-m-d H:i:s');

        $paymentId =
            (int)$record['payment_id'];

        $subscriptionId =
            (int)$record['subscription_id'];

        $userId =
            (int)$record['user_id'];

        /*
        |--------------------------------------------------------------------------
        | Activate transaction
        |--------------------------------------------------------------------------
        */

        $this->db->begin_transaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Mark payment successful
            |--------------------------------------------------------------------------
            */

            $paymentStmt = $this->db->prepare("
                UPDATE subscription_payments

                SET
                    status = 'successful',
                    provider_reference = ?,
                    paid_at = NOW()

                WHERE id = ?
                  AND status = 'pending'
            ");

            $paymentStmt->bind_param(
                'si',
                $providerReference,
                $paymentId
            );

            $paymentStmt->execute();

            if ($paymentStmt->affected_rows !== 1) {
                throw new RuntimeException(
                    'Subscription payment could not be confirmed.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Expire older active subscriptions
            |--------------------------------------------------------------------------
            */

            $expireStmt = $this->db->prepare("
                UPDATE subscriptions

                SET status = 'expired'

                WHERE user_id = ?
                  AND id <> ?
                  AND status = 'active'
            ");

            $expireStmt->bind_param(
                'ii',
                $userId,
                $subscriptionId
            );

            $expireStmt->execute();

            /*
            |--------------------------------------------------------------------------
            | Activate subscription
            |--------------------------------------------------------------------------
            */

            $subscriptionStmt = $this->db->prepare("
                UPDATE subscriptions

                SET
                    status = 'active',
                    starts_at = ?,
                    expires_at = ?

                WHERE id = ?
                  AND user_id = ?
            ");

            $subscriptionStmt->bind_param(
                'ssii',
                $startsAtString,
                $expiresAtString,
                $subscriptionId,
                $userId
            );

            $subscriptionStmt->execute();

            if ($subscriptionStmt->affected_rows !== 1) {
                throw new RuntimeException(
                    'Subscription could not be activated.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Upgrade user account
            |--------------------------------------------------------------------------
            */

            $userStmt = $this->db->prepare("
                UPDATE users

                SET plan = 'pro'

                WHERE id = ?
            ");

            $userStmt->bind_param(
                'i',
                $userId
            );

            $userStmt->execute();

            $this->db->commit();

        } catch (Throwable $e) {

            $this->db->rollback();

            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | Result
        |--------------------------------------------------------------------------
        */

        return [
            'already_active' => false,

            'payment_id' =>
                $paymentId,

            'subscription_id' =>
                $subscriptionId,

            'user_id' =>
                $userId,

            'plan' =>
                'pro',

            'billing_cycle' =>
                $billingCycle,

            'starts_at' =>
                $startsAtString,

            'expires_at' =>
                $expiresAtString
        ];
    }


    /**
     * Mark failed subscription payment.
     */
    public function failPayment(
        string $reference
    ): void {

        $reference =
            trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException(
                'Payment reference is required.'
            );
        }

        $stmt = $this->db->prepare("
            UPDATE subscription_payments

            SET status = 'failed'

            WHERE reference = ?
              AND status = 'pending'
        ");

        $stmt->bind_param(
            's',
            $reference
        );

        $stmt->execute();
    }
}

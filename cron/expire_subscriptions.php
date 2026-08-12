<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
| Script hii ni ya CLI / Cron pekee.
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

$db = db();

$now = date('Y-m-d H:i:s');

echo "============================================\n";
echo "InvoicePro NG Subscription Expiry Job\n";
echo "Time: {$now}\n";
echo "============================================\n";

/*
|--------------------------------------------------------------------------
| Find expired active subscriptions
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        user_id,
        plan,
        billing_cycle,
        starts_at,
        expires_at

    FROM subscriptions

    WHERE status = 'active'
      AND expires_at IS NOT NULL
      AND expires_at <= ?

    ORDER BY expires_at ASC
");

$stmt->bind_param(
    's',
    $now
);

$stmt->execute();

$subscriptions = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

if (!$subscriptions) {

    echo "No expired subscriptions found.\n";
    echo "============================================\n";

    exit(0);
}

echo "Expired subscriptions found: "
    . count($subscriptions)
    . "\n";

/*
|--------------------------------------------------------------------------
| Process each expired subscription
|--------------------------------------------------------------------------
*/

foreach ($subscriptions as $subscription) {

    $subscriptionId =
        (int)$subscription['id'];

    $userId =
        (int)$subscription['user_id'];

    echo "\n--------------------------------------------\n";

    echo "Processing subscription #{$subscriptionId}\n";
    echo "User ID: {$userId}\n";
    echo "Expired at: {$subscription['expires_at']}\n";

    $db->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Lock subscription
        |--------------------------------------------------------------------------
        |
        | This protects against another process updating the same subscription
        | while this cron job is running.
        |--------------------------------------------------------------------------
        */

        $lockStmt = $db->prepare("
            SELECT
                id,
                status,
                expires_at

            FROM subscriptions

            WHERE id = ?

            FOR UPDATE
        ");

        $lockStmt->bind_param(
            'i',
            $subscriptionId
        );

        $lockStmt->execute();

        $lockedSubscription =
            $lockStmt
                ->get_result()
                ->fetch_assoc();

        if (!$lockedSubscription) {

            $db->rollback();

            echo "Subscription no longer exists.\n";

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Check again after lock
        |--------------------------------------------------------------------------
        */

        if (
            $lockedSubscription['status'] !== 'active'
        ) {

            $db->rollback();

            echo "Subscription is no longer active. Skipped.\n";

            continue;
        }

        if (
            empty($lockedSubscription['expires_at'])
            || $lockedSubscription['expires_at'] > $now
        ) {

            $db->rollback();

            echo "Subscription has not expired. Skipped.\n";

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Mark subscription expired
        |--------------------------------------------------------------------------
        */

        $expireStmt = $db->prepare("
            UPDATE subscriptions

            SET status = 'expired'

            WHERE id = ?
              AND status = 'active'
        ");

        $expireStmt->bind_param(
            'i',
            $subscriptionId
        );

        $expireStmt->execute();

        /*
        |--------------------------------------------------------------------------
        | Check for another active Pro subscription
        |--------------------------------------------------------------------------
        |
        | User may already have another valid subscription.
        |--------------------------------------------------------------------------
        */

        $activeStmt = $db->prepare("
            SELECT id

            FROM subscriptions

            WHERE user_id = ?
              AND plan = 'pro'
              AND status = 'active'
              AND (
                    expires_at IS NULL
                    OR expires_at > ?
                  )

            LIMIT 1
        ");

        $activeStmt->bind_param(
            'is',
            $userId,
            $now
        );

        $activeStmt->execute();

        $anotherActiveSubscription =
            $activeStmt
                ->get_result()
                ->fetch_assoc();

        /*
        |--------------------------------------------------------------------------
        | Downgrade user only when no valid Pro subscription remains
        |--------------------------------------------------------------------------
        */

        if (!$anotherActiveSubscription) {

            $userStmt = $db->prepare("
                UPDATE users

                SET plan = 'free'

                WHERE id = ?
                  AND plan = 'pro'
            ");

            $userStmt->bind_param(
                'i',
                $userId
            );

            $userStmt->execute();

            echo "User downgraded to FREE plan.\n";

        } else {

            echo
                "User still has another active Pro subscription. "
                . "Plan remains PRO.\n";
        }

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $db->commit();

        echo
            "Subscription #{$subscriptionId} "
            . "marked as expired successfully.\n";

    } catch (Throwable $e) {

        $db->rollback();

        echo
            "Subscription #{$subscriptionId} failed: "
            . $e->getMessage()
            . "\n";
    }
}

/*
|--------------------------------------------------------------------------
| Cleanup pending subscriptions
|--------------------------------------------------------------------------
|
| Optional cleanup:
| Pending subscriptions that were never paid can be cancelled after 24 hours.
|--------------------------------------------------------------------------
*/

try {

    $cleanupStmt = $db->prepare("
        UPDATE subscriptions s

        LEFT JOIN subscription_payments sp
            ON sp.subscription_id = s.id

        SET s.status = 'cancelled'

        WHERE s.status = 'pending'
          AND s.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND (
                sp.id IS NULL
                OR sp.status IN (
                    'pending',
                    'failed',
                    'cancelled'
                )
              )
    ");

    $cleanupStmt->execute();

    $cancelledPending =
        $cleanupStmt->affected_rows;

    if ($cancelledPending > 0) {

        echo "\nCancelled stale pending subscriptions: "
            . $cancelledPending
            . "\n";
    }

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Cleanup failure should not invalidate expiry processing
    |--------------------------------------------------------------------------
    */

    echo
        "\nPending subscription cleanup warning: "
        . $e->getMessage()
        . "\n";
}

/*
|--------------------------------------------------------------------------
| Finished
|--------------------------------------------------------------------------
*/

echo "\n============================================\n";
echo "Subscription expiry job completed.\n";
echo "============================================\n";

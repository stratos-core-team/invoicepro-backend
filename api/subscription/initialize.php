<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$input = request_json();

$billingCycle = strtolower(
    trim((string)($input['billing_cycle'] ?? ''))
);

$allowedCycles = [
    'monthly',
    'yearly'
];

if (!in_array($billingCycle, $allowedCycles, true)) {
    api_error(
        'Billing cycle must be monthly or yearly.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Pro pricing
|--------------------------------------------------------------------------
|
| PRD:
| Monthly = ₦2,500
| Yearly  = ₦25,000
|--------------------------------------------------------------------------
*/

$amount = match ($billingCycle) {
    'monthly' => 2500.00,
    'yearly'  => 25000.00
};

/*
|--------------------------------------------------------------------------
| Check current subscription
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        plan,
        billing_cycle,
        status,
        expires_at

    FROM subscriptions

    WHERE user_id = ?

    ORDER BY id DESC
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $user['id']
);

$stmt->execute();

$current = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Prevent unnecessary duplicate upgrade
|--------------------------------------------------------------------------
*/

if (
    $current
    && $current['plan'] === 'pro'
    && $current['status'] === 'active'
    && (
        empty($current['expires_at'])
        || $current['expires_at'] > date('Y-m-d H:i:s')
    )
) {
    api_error(
        'You already have an active Pro subscription.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| Reference
|--------------------------------------------------------------------------
*/

$reference =
    'SUB-'
    . strtoupper(
        bin2hex(
            random_bytes(6)
        )
    );

/*
|--------------------------------------------------------------------------
| Create pending subscription
|--------------------------------------------------------------------------
|
| Important:
| User is NOT upgraded to Pro here.
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    $stmt = $db->prepare("
        INSERT INTO subscriptions
        (
            user_id,
            plan,
            billing_cycle,
            status,
            starts_at,
            expires_at
        )
        VALUES
        (
            ?,
            'pro',
            ?,
            'pending',
            NULL,
            NULL
        )
    ");

    $stmt->bind_param(
        'is',
        $user['id'],
        $billingCycle
    );

    $stmt->execute();

    $subscriptionId =
        (int)$db->insert_id;

    /*
    |--------------------------------------------------------------------------
    | Create subscription payment
    |--------------------------------------------------------------------------
    */

    $payment = $db->prepare("
        INSERT INTO subscription_payments
        (
            subscription_id,
            user_id,
            reference,
            provider,
            amount,
            currency,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            'payscribe',
            ?,
            'NGN',
            'pending'
        )
    ");

    $payment->bind_param(
        'iisd',
        $subscriptionId,
        $user['id'],
        $reference,
        $amount
    );

    $payment->execute();

    $paymentId =
        (int)$db->insert_id;

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not initialize subscription upgrade.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
|
| We deliberately do not return a fake Payscribe payment URL.
| Provider initialization will be connected after official
| Payscribe API details are confirmed.
|--------------------------------------------------------------------------
*/

api_success([
    'subscription' => [
        'id' => $subscriptionId,
        'plan' => 'pro',
        'billing_cycle' => $billingCycle,
        'status' => 'pending'
    ],

    'payment' => [
        'id' => $paymentId,
        'reference' => $reference,
        'provider' => 'payscribe',
        'amount' => $amount,
        'currency' => 'NGN',
        'status' => 'pending'
    ]
], 'Subscription upgrade initialized.', 201);

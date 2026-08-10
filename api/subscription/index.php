<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$stmt = $db->prepare("
    SELECT
        id,
        plan,
        billing_cycle,
        status,
        starts_at,
        expires_at,
        created_at

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

$subscription = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$subscription) {

    $subscription = [
        'plan' => $user['plan'],
        'billing_cycle' => null,
        'status' => 'active',
        'starts_at' => null,
        'expires_at' => null
    ];
}

api_success([
    'subscription' => [
        'plan' =>
            $subscription['plan'],

        'billing_cycle' =>
            $subscription['billing_cycle'],

        'status' =>
            $subscription['status'],

        'starts_at' =>
            $subscription['starts_at'],

        'expires_at' =>
            $subscription['expires_at']
    ],

    'features' => [
        'analytics' =>
            $user['plan'] === 'pro',

        'custom_logo' =>
            $user['plan'] === 'pro',

        'remove_watermark' =>
            $user['plan'] === 'pro',

        'priority_support' =>
            $user['plan'] === 'pro'
    ]
]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../services/PayscribeService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$rawPayload = file_get_contents('php://input') ?: '';

if ($rawPayload === '') {
    api_error('Empty webhook payload.', 400);
}

$payload = json_decode($rawPayload, true);

if (!is_array($payload)) {
    api_error('Invalid webhook JSON.', 400);
}

/*
|--------------------------------------------------------------------------
| Read signature
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Replace this header with the exact Payscribe signature header
| after confirming it from official documentation.
|
*/

$signature =
    $_SERVER['HTTP_X_PAYSCRIBE_SIGNATURE']
    ?? null;

/*
|--------------------------------------------------------------------------
| Verify webhook
|--------------------------------------------------------------------------
*/

try {

    $payscribe = new PayscribeService();

    $verified = $payscribe->verifyWebhookSignature(
        $rawPayload,
        $signature
    );

} catch (Throwable $e) {

    api_error(
        'Webhook verification configuration error.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Reject unverified webhook
|--------------------------------------------------------------------------
*/

if (!$verified) {

    api_error(
        'Invalid or unverified webhook signature.',
        401
    );
}

/*
|--------------------------------------------------------------------------
| Determine event ID
|--------------------------------------------------------------------------
*/

$eventId = (string)(
    $payload['id']
    ?? $payload['event_id']
    ?? hash('sha256', $rawPayload)
);

/*
|--------------------------------------------------------------------------
| Prevent duplicate processing
|--------------------------------------------------------------------------
*/

$db = db();

$stmt = $db->prepare("
    SELECT id, processed
    FROM webhook_events
    WHERE provider = 'payscribe'
      AND event_id = ?
    LIMIT 1
");

$stmt->bind_param(
    's',
    $eventId
);

$stmt->execute();

$existing = $stmt
    ->get_result()
    ->fetch_assoc();

if ($existing) {

    api_success([
        'event_id' => $eventId,
        'duplicate' => true
    ], 'Webhook already received.');
}

/*
|--------------------------------------------------------------------------
| Store verified webhook
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    INSERT INTO webhook_events
    (
        provider,
        event_id,
        payload,
        processed
    )
    VALUES
    (
        'payscribe',
        ?,
        ?,
        0
    )
");

$stmt->bind_param(
    'ss',
    $eventId,
    $rawPayload
);

$stmt->execute();

/*
|--------------------------------------------------------------------------
| Important
|--------------------------------------------------------------------------
|
| We deliberately DO NOT update payments/invoices here yet.
|
| First we need official Payscribe documentation confirming:
|
| 1. Signature header
| 2. Signature algorithm
| 3. Event type names
| 4. Payment status names
| 5. Transaction/reference field
| 6. Verification endpoint
|
*/

api_success([
    'event_id' => $eventId,
    'received' => true,
    'processed' => false
], 'Webhook received and verified.');

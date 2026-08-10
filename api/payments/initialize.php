<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../services/PayscribeService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$input = request_json();

$invoiceId = (int)($input['invoice_id'] ?? 0);

if ($invoiceId <= 0) {
    api_error(
        'A valid invoice_id is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Fetch invoice
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.total,
        i.amount_paid,
        i.status,
        i.public_token,

        c.id AS customer_id,
        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone

    FROM invoices i

    INNER JOIN customers c
        ON c.id = i.customer_id

    WHERE i.id = ?
      AND i.user_id = ?

    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $invoiceId,
    $user['id']
);

$stmt->execute();

$invoice = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$invoice) {
    api_error(
        'Invoice not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Invoice status checks
|--------------------------------------------------------------------------
*/

if ($invoice['status'] === 'cancelled') {
    api_error(
        'Cancelled invoices cannot be paid.',
        409
    );
}

if ($invoice['status'] === 'paid') {
    api_error(
        'Invoice is already paid.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| Calculate outstanding balance
|--------------------------------------------------------------------------
*/

$total = (float)$invoice['total'];
$amountPaid = (float)$invoice['amount_paid'];

$balance = round(
    $total - $amountPaid,
    2
);

if ($balance <= 0) {
    api_error(
        'Invoice has no outstanding balance.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| Generate internal payment reference
|--------------------------------------------------------------------------
*/

$reference =
    'INVPRO-' .
    strtoupper(
        bin2hex(
            random_bytes(6)
        )
    );

/*
|--------------------------------------------------------------------------
| Create pending payment record
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    $paymentStmt = $db->prepare("
        INSERT INTO payments
        (
            invoice_id,
            reference,
            provider,
            amount,
            method,
            status
        )
        VALUES
        (
            ?,
            ?,
            'payscribe',
            ?,
            'online',
            'pending'
        )
    ");

    $paymentStmt->bind_param(
        'isd',
        $invoiceId,
        $reference,
        $balance
    );

    $paymentStmt->execute();

    $paymentId = (int)$db->insert_id;

    /*
    |--------------------------------------------------------------------------
    | Build provider payload
    |--------------------------------------------------------------------------
    |
    | Field names below are intentionally generic.
    | Once official Payscribe API documentation is supplied,
    | map these fields to Payscribe's exact payload format.
    |
    */

    $paymentData = [
        'reference' => $reference,
        'amount' => $balance,
        'currency' => 'NGN',

        'customer' => [
            'name' => $invoice['customer_name'],
            'email' => $invoice['customer_email'],
            'phone' => $invoice['customer_phone']
        ],

        'metadata' => [
            'invoice_id' => $invoiceId,
            'invoice_number' =>
                $invoice['invoice_number'],

            'user_id' =>
                (int)$user['id']
        ]
    ];

    /*
    |--------------------------------------------------------------------------
    | Call Payscribe
    |--------------------------------------------------------------------------
    */

    $payscribe = new PayscribeService();

    $providerResponse =
        $payscribe->initializePayment(
            $paymentData
        );

    /*
    |--------------------------------------------------------------------------
    | Try to capture provider reference
    |--------------------------------------------------------------------------
    |
    | Since official response keys are not yet confirmed,
    | support a few common names without relying on them
    | for payment confirmation.
    |
    */

    $providerReference =
        $providerResponse['reference']
        ?? $providerResponse['data']['reference']
        ?? $providerResponse['transaction_reference']
        ?? null;

    if ($providerReference) {

        $updatePayment = $db->prepare("
            UPDATE payments
            SET provider_reference = ?
            WHERE id = ?
        ");

        $updatePayment->bind_param(
            'si',
            $providerReference,
            $paymentId
        );

        $updatePayment->execute();
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not initialize payment: '
        . $e->getMessage(),
        502
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'payment' => [
        'id' => $paymentId,
        'reference' => $reference,
        'provider' => 'payscribe',
        'amount' => $balance,
        'currency' => 'NGN',
        'status' => 'pending'
    ],

    'invoice' => [
        'id' => $invoiceId,
        'invoice_number' =>
            $invoice['invoice_number'],

        'total' => $total,
        'amount_paid' => $amountPaid,
        'balance' => $balance
    ],

    'provider' => $providerResponse

], 'Payment initialized successfully.', 201);

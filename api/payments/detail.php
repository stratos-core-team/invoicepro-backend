<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$paymentId = (int)($_GET['id'] ?? 0);

if ($paymentId <= 0) {
    api_error(
        'A valid payment id is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Fetch payment
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        p.id,
        p.invoice_id,
        p.reference,
        p.provider,
        p.amount,
        p.method,
        p.status,
        p.provider_reference,
        p.paid_at,
        p.created_at,

        i.invoice_number,
        i.total AS invoice_total,
        i.amount_paid AS invoice_amount_paid,
        i.status AS invoice_status,

        c.id AS customer_id,
        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone

    FROM payments p

    INNER JOIN invoices i
        ON i.id = p.invoice_id

    INNER JOIN customers c
        ON c.id = i.customer_id

    WHERE p.id = ?
      AND i.user_id = ?

    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $paymentId,
    $user['id']
);

$stmt->execute();

$payment = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$payment) {
    api_error(
        'Payment not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Calculate invoice balance
|--------------------------------------------------------------------------
*/

$invoiceTotal =
    (float)$payment['invoice_total'];

$invoiceAmountPaid =
    (float)$payment['invoice_amount_paid'];

$invoiceBalance = max(
    0,
    round(
        $invoiceTotal - $invoiceAmountPaid,
        2
    )
);

$payment['amount'] =
    (float)$payment['amount'];

$payment['invoice_total'] =
    $invoiceTotal;

$payment['invoice_amount_paid'] =
    $invoiceAmountPaid;

$payment['invoice_balance'] =
    $invoiceBalance;

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'payment' => $payment
]);

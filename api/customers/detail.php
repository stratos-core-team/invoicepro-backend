<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$customerId = (int)($_GET['id'] ?? 0);

if ($customerId <= 0) {
    api_error('A valid customer id is required.', 422);
}

/*
|--------------------------------------------------------------------------
| Fetch customer
|--------------------------------------------------------------------------
| user_id check prevents one account from viewing another user's customer.
*/

$stmt = $db->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        address,
        created_at,
        updated_at
    FROM customers
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $customerId,
    $user['id']
);

$stmt->execute();

$customer = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$customer) {
    api_error('Customer not found.', 404);
}

/*
|--------------------------------------------------------------------------
| Customer invoice statistics
|--------------------------------------------------------------------------
*/

$statsStmt = $db->prepare("
    SELECT
        COUNT(*) AS total_invoices,

        COALESCE(
            SUM(CASE
                WHEN status = 'paid'
                THEN 1
                ELSE 0
            END),
            0
        ) AS paid_invoices,

        COALESCE(
            SUM(CASE
                WHEN status = 'unpaid'
                THEN 1
                ELSE 0
            END),
            0
        ) AS unpaid_invoices,

        COALESCE(SUM(total), 0) AS total_invoiced,

        COALESCE(SUM(amount_paid), 0) AS total_paid,

        COALESCE(
            SUM(total - amount_paid),
            0
        ) AS outstanding_balance

    FROM invoices

    WHERE customer_id = ?
      AND user_id = ?
");

$statsStmt->bind_param(
    'ii',
    $customerId,
    $user['id']
);

$statsStmt->execute();

$stats = $statsStmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Recent customer invoices
|--------------------------------------------------------------------------
*/

$invoiceStmt = $db->prepare("
    SELECT
        id,
        invoice_number,
        issue_date,
        due_date,
        total,
        amount_paid,
        status,
        created_at

    FROM invoices

    WHERE customer_id = ?
      AND user_id = ?

    ORDER BY id DESC

    LIMIT 10
");

$invoiceStmt->bind_param(
    'ii',
    $customerId,
    $user['id']
);

$invoiceStmt->execute();

$invoices = $invoiceStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Add overdue display status
|--------------------------------------------------------------------------
*/

foreach ($invoices as &$invoice) {

    if (
        $invoice['status'] === 'unpaid'
        && $invoice['due_date'] < date('Y-m-d')
    ) {
        $invoice['display_status'] = 'overdue';
    } else {
        $invoice['display_status'] = $invoice['status'];
    }

    $invoice['balance'] = max(
        0,
        round(
            (float)$invoice['total']
            - (float)$invoice['amount_paid'],
            2
        )
    );
}

unset($invoice);

/*
|--------------------------------------------------------------------------
| Normalize statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'total_invoices' =>
        (int)$stats['total_invoices'],

    'paid_invoices' =>
        (int)$stats['paid_invoices'],

    'unpaid_invoices' =>
        (int)$stats['unpaid_invoices'],

    'total_invoiced' =>
        (float)$stats['total_invoiced'],

    'total_paid' =>
        (float)$stats['total_paid'],

    'outstanding_balance' =>
        (float)$stats['outstanding_balance']
];

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'customer' => $customer,
    'statistics' => $stats,
    'recent_invoices' => $invoices
]);
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();

$invoiceId = (int)($_GET['id'] ?? 0);

if ($invoiceId <= 0) {
    api_error('A valid invoice id is required.', 422);
}

$db = db();

/*
|--------------------------------------------------------------------------
| Fetch invoice
|--------------------------------------------------------------------------
| Important:
| We check both invoice ID and user_id so one user cannot access
| another user's invoice.
*/
$stmt = $db->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.issue_date,
        i.due_date,
        i.subtotal,
        i.tax_rate,
        i.tax_amount,
        i.total,
        i.amount_paid,
        i.status,
        i.notes,
        i.public_token,
        i.paid_at,
        i.created_at,
        i.updated_at,

        c.id AS customer_id,
        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone,
        c.address AS customer_address

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
    api_error('Invoice not found.', 404);
}


/*
|--------------------------------------------------------------------------
| Fetch invoice items
|--------------------------------------------------------------------------
*/

$itemStmt = $db->prepare("
    SELECT
        id,
        description,
        quantity,
        unit_price,
        line_total
    FROM invoice_items
    WHERE invoice_id = ?
    ORDER BY id ASC
");

$itemStmt->bind_param(
    'i',
    $invoiceId
);

$itemStmt->execute();

$items = $itemStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);


/*
|--------------------------------------------------------------------------
| Calculate display status
|--------------------------------------------------------------------------
*/

$displayStatus = $invoice['status'];

if (
    $invoice['status'] === 'unpaid'
    && $invoice['due_date'] < date('Y-m-d')
) {
    $displayStatus = 'overdue';
}


/*
|--------------------------------------------------------------------------
| Calculate remaining balance
|--------------------------------------------------------------------------
*/

$total = (float)$invoice['total'];
$amountPaid = (float)$invoice['amount_paid'];

$balance = max(
    0,
    round($total - $amountPaid, 2)
);


/*
|--------------------------------------------------------------------------
| Prepare response
|--------------------------------------------------------------------------
*/

$invoice['display_status'] = $displayStatus;
$invoice['balance'] = $balance;
$invoice['items'] = $items;


/*
|--------------------------------------------------------------------------
| Return invoice
|--------------------------------------------------------------------------
*/

api_success([
    'invoice' => $invoice
]);
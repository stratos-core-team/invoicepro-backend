<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$recurringId = (int)($_GET['id'] ?? 0);

if ($recurringId <= 0) {
    api_error(
        'A valid recurring invoice id is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Fetch recurring invoice
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        r.id,
        r.customer_id,
        r.frequency,
        r.tax_rate,
        r.notes,
        r.next_run_date,
        r.start_date,
        r.end_date,
        r.active,
        r.created_at,

        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone,
        c.address AS customer_address

    FROM recurring_invoices r

    INNER JOIN customers c
        ON c.id = r.customer_id

    WHERE r.id = ?
      AND r.user_id = ?

    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $recurringId,
    $user['id']
);

$stmt->execute();

$recurring = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$recurring) {
    api_error(
        'Recurring invoice not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Fetch template items
|--------------------------------------------------------------------------
*/

$itemStmt = $db->prepare("
    SELECT
        id,
        description,
        quantity,
        unit_price

    FROM recurring_invoice_items

    WHERE recurring_invoice_id = ?

    ORDER BY id ASC
");

$itemStmt->bind_param(
    'i',
    $recurringId
);

$itemStmt->execute();

$items = $itemStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Calculate totals
|--------------------------------------------------------------------------
*/

$subtotal = 0.0;

foreach ($items as &$item) {

    $quantity = (float)$item['quantity'];
    $unitPrice = (float)$item['unit_price'];

    $lineTotal = round(
        $quantity * $unitPrice,
        2
    );

    $item['id'] = (int)$item['id'];
    $item['quantity'] = $quantity;
    $item['unit_price'] = $unitPrice;
    $item['line_total'] = $lineTotal;

    $subtotal += $lineTotal;
}

unset($item);

$subtotal = round($subtotal, 2);

$taxRate = (float)$recurring['tax_rate'];

$taxAmount = round(
    $subtotal * ($taxRate / 100),
    2
);

$total = round(
    $subtotal + $taxAmount,
    2
);

/*
|--------------------------------------------------------------------------
| Generated invoice statistics
|--------------------------------------------------------------------------
|
| At this stage there is no recurring_invoice_id column on invoices,
| so we do not pretend to know exactly which invoices were generated
| by this schedule.
|
| Later we should add recurring_invoice_id to invoices so generation
| history can be tracked reliably.
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Normalize values
|--------------------------------------------------------------------------
*/

$recurring['id'] =
    (int)$recurring['id'];

$recurring['customer_id'] =
    (int)$recurring['customer_id'];

$recurring['tax_rate'] =
    $taxRate;

$recurring['active'] =
    (bool)$recurring['active'];

$recurring['items'] =
    $items;

$recurring['subtotal'] =
    $subtotal;

$recurring['tax_amount'] =
    $taxAmount;

$recurring['total'] =
    $total;

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'recurring_invoice' => $recurring
]);

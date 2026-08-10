<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$invoiceId = (int)($_GET['id'] ?? 0);

if ($invoiceId <= 0) {
    api_error('A valid invoice id is required.', 422);
}

/*
|--------------------------------------------------------------------------
| Check invoice ownership
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        customer_id,
        issue_date,
        due_date,
        tax_rate,
        notes,
        status
    FROM invoices
    WHERE id = ?
      AND user_id = ?
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
| Prevent editing paid invoices
|--------------------------------------------------------------------------
*/

if ($invoice['status'] === 'paid') {
    api_error('Paid invoices cannot be edited.', 409);
}


/*
|--------------------------------------------------------------------------
| Read request body
|--------------------------------------------------------------------------
*/

$input = request_json();

$customerId = isset($input['customer_id'])
    ? (int)$input['customer_id']
    : (int)$invoice['customer_id'];

$issueDate = isset($input['issue_date'])
    ? (string)$input['issue_date']
    : $invoice['issue_date'];

$dueDate = isset($input['due_date'])
    ? (string)$input['due_date']
    : $invoice['due_date'];

$taxRate = isset($input['tax_rate'])
    ? max(0, (float)$input['tax_rate'])
    : (float)$invoice['tax_rate'];

$notes = array_key_exists('notes', $input)
    ? trim((string)$input['notes'])
    : (string)$invoice['notes'];

$items = $input['items'] ?? null;


/*
|--------------------------------------------------------------------------
| Validate customer
|--------------------------------------------------------------------------
*/

if ($customerId <= 0) {
    api_error('A valid customer_id is required.', 422);
}

$customerStmt = $db->prepare("
    SELECT id
    FROM customers
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");

$customerStmt->bind_param(
    'ii',
    $customerId,
    $user['id']
);

$customerStmt->execute();

if (!$customerStmt->get_result()->fetch_assoc()) {
    api_error('Customer not found.', 404);
}


/*
|--------------------------------------------------------------------------
| Validate dates
|--------------------------------------------------------------------------
*/

$issueTimestamp = strtotime($issueDate);
$dueTimestamp = strtotime($dueDate);

if ($issueTimestamp === false || $dueTimestamp === false) {
    api_error('Invalid issue_date or due_date.', 422);
}

if ($dueTimestamp < $issueTimestamp) {
    api_error('Due date cannot be earlier than issue date.', 422);
}


/*
|--------------------------------------------------------------------------
| If items were supplied, recalculate invoice
|--------------------------------------------------------------------------
*/

$normalizedItems = null;

if ($items !== null) {

    if (!is_array($items) || count($items) === 0) {
        api_error('At least one invoice item is required.', 422);
    }

    $normalizedItems = [];
    $subtotal = 0.0;

    foreach ($items as $item) {

        $description = trim(
            (string)($item['description'] ?? '')
        );

        $quantity = (float)(
            $item['quantity'] ?? 0
        );

        $unitPrice = (float)(
            $item['unit_price'] ?? -1
        );

        if (
            $description === ''
            || $quantity <= 0
            || $unitPrice < 0
        ) {
            api_error(
                'Each item requires description, quantity > 0 and unit_price >= 0.',
                422
            );
        }

        $lineTotal = round(
            $quantity * $unitPrice,
            2
        );

        $subtotal += $lineTotal;

        $normalizedItems[] = [
            $description,
            $quantity,
            $unitPrice,
            $lineTotal
        ];
    }

    $subtotal = round($subtotal, 2);

} else {

    /*
    |--------------------------------------------------------------------------
    | Existing subtotal
    |--------------------------------------------------------------------------
    */

    $subtotalStmt = $db->prepare("
        SELECT subtotal
        FROM invoices
        WHERE id = ?
        LIMIT 1
    ");

    $subtotalStmt->bind_param(
        'i',
        $invoiceId
    );

    $subtotalStmt->execute();

    $subtotalRow = $subtotalStmt
        ->get_result()
        ->fetch_assoc();

    $subtotal = (float)$subtotalRow['subtotal'];
}


/*
|--------------------------------------------------------------------------
| Recalculate totals
|--------------------------------------------------------------------------
*/

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
| Update transaction
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    $updateStmt = $db->prepare("
        UPDATE invoices
        SET
            customer_id = ?,
            issue_date = ?,
            due_date = ?,
            subtotal = ?,
            tax_rate = ?,
            tax_amount = ?,
            total = ?,
            notes = ?
        WHERE id = ?
          AND user_id = ?
    ");

    $updateStmt->bind_param(
        'issddddsii',
        $customerId,
        $issueDate,
        $dueDate,
        $subtotal,
        $taxRate,
        $taxAmount,
        $total,
        $notes,
        $invoiceId,
        $user['id']
    );

    $updateStmt->execute();


    /*
    |--------------------------------------------------------------------------
    | Replace invoice items only when supplied
    |--------------------------------------------------------------------------
    */

    if ($normalizedItems !== null) {

        $deleteItems = $db->prepare("
            DELETE FROM invoice_items
            WHERE invoice_id = ?
        ");

        $deleteItems->bind_param(
            'i',
            $invoiceId
        );

        $deleteItems->execute();


        $itemStmt = $db->prepare("
            INSERT INTO invoice_items
            (
                invoice_id,
                description,
                quantity,
                unit_price,
                line_total
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($normalizedItems as $row) {

            $itemStmt->bind_param(
                'isddd',
                $invoiceId,
                $row[0],
                $row[1],
                $row[2],
                $row[3]
            );

            $itemStmt->execute();
        }
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not update invoice.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Return updated invoice
|--------------------------------------------------------------------------
*/

api_success([
    'invoice' => [
        'id' => $invoiceId,
        'customer_id' => $customerId,
        'issue_date' => $issueDate,
        'due_date' => $dueDate,
        'subtotal' => $subtotal,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'total' => $total,
        'status' => $invoice['status']
    ]
], 'Invoice updated successfully.');
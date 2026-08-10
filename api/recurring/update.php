<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
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
        id,
        customer_id,
        frequency,
        tax_rate,
        notes,
        next_run_date,
        start_date,
        end_date,
        active

    FROM recurring_invoices

    WHERE id = ?
      AND user_id = ?

    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $recurringId,
    $user['id']
);

$stmt->execute();

$current = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$current) {
    api_error(
        'Recurring invoice not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Read request
|--------------------------------------------------------------------------
*/

$input = request_json();

$customerId = array_key_exists('customer_id', $input)
    ? (int)$input['customer_id']
    : (int)$current['customer_id'];

$frequency = array_key_exists('frequency', $input)
    ? strtolower(trim((string)$input['frequency']))
    : (string)$current['frequency'];

$taxRate = array_key_exists('tax_rate', $input)
    ? (float)$input['tax_rate']
    : (float)$current['tax_rate'];

$notes = array_key_exists('notes', $input)
    ? trim((string)$input['notes'])
    : (string)($current['notes'] ?? '');

$startDate = array_key_exists('start_date', $input)
    ? trim((string)$input['start_date'])
    : (string)$current['start_date'];

$nextRunDate = array_key_exists('next_run_date', $input)
    ? trim((string)$input['next_run_date'])
    : (string)$current['next_run_date'];

$endDate = array_key_exists('end_date', $input)
    ? trim((string)$input['end_date'])
    : (string)($current['end_date'] ?? '');

$items = $input['items'] ?? null;

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

$errors = [];

if ($customerId <= 0) {
    $errors['customer_id'] =
        'A valid customer_id is required.';
}

$allowedFrequencies = [
    'weekly',
    'monthly',
    'yearly'
];

if (!in_array(
    $frequency,
    $allowedFrequencies,
    true
)) {
    $errors['frequency'] =
        'Frequency must be weekly, monthly or yearly.';
}

if ($taxRate < 0 || $taxRate > 100) {
    $errors['tax_rate'] =
        'Tax rate must be between 0 and 100.';
}

if (!valid_date($startDate)) {
    $errors['start_date'] =
        'Start date must use YYYY-MM-DD.';
}

if (!valid_date($nextRunDate)) {
    $errors['next_run_date'] =
        'Next run date must use YYYY-MM-DD.';
}

if (
    $endDate !== ''
    && !valid_date($endDate)
) {
    $errors['end_date'] =
        'End date must use YYYY-MM-DD.';
}

if (
    $endDate !== ''
    && valid_date($endDate)
    && $endDate < $startDate
) {
    $errors['end_date'] =
        'End date cannot be earlier than start date.';
}

if ($errors) {
    api_error(
        'Validation failed.',
        422,
        $errors
    );
}

/*
|--------------------------------------------------------------------------
| Verify customer ownership
|--------------------------------------------------------------------------
*/

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

if (
    !$customerStmt
        ->get_result()
        ->fetch_assoc()
) {
    api_error(
        'Customer not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Validate new items if supplied
|--------------------------------------------------------------------------
*/

$normalizedItems = null;

if ($items !== null) {

    if (
        !is_array($items)
        || count($items) === 0
    ) {
        api_error(
            'At least one recurring invoice item is required.',
            422
        );
    }

    $normalizedItems = [];

    foreach ($items as $index => $item) {

        if (!is_array($item)) {
            api_error(
                'Invalid recurring invoice item.',
                422
            );
        }

        $description = trim(
            (string)($item['description'] ?? '')
        );

        $quantity = (float)(
            $item['quantity'] ?? 0
        );

        $unitPrice = (float)(
            $item['unit_price'] ?? -1
        );

        if ($description === '') {
            api_error(
                'Item description is required.',
                422,
                ['item' => $index]
            );
        }

        if ($quantity <= 0) {
            api_error(
                'Item quantity must be greater than zero.',
                422,
                ['item' => $index]
            );
        }

        if ($unitPrice < 0) {
            api_error(
                'Item unit price cannot be negative.',
                422,
                ['item' => $index]
            );
        }

        $normalizedItems[] = [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Transaction
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Update schedule
    |--------------------------------------------------------------------------
    */

    if ($endDate === '') {

        $update = $db->prepare("
            UPDATE recurring_invoices

            SET
                customer_id = ?,
                frequency = ?,
                tax_rate = ?,
                notes = ?,
                next_run_date = ?,
                start_date = ?,
                end_date = NULL

            WHERE id = ?
              AND user_id = ?
        ");

        $update->bind_param(
            'isdsssii',
            $customerId,
            $frequency,
            $taxRate,
            $notes,
            $nextRunDate,
            $startDate,
            $recurringId,
            $user['id']
        );

    } else {

        $update = $db->prepare("
            UPDATE recurring_invoices

            SET
                customer_id = ?,
                frequency = ?,
                tax_rate = ?,
                notes = ?,
                next_run_date = ?,
                start_date = ?,
                end_date = ?

            WHERE id = ?
              AND user_id = ?
        ");

        $update->bind_param(
            'isdssssii',
            $customerId,
            $frequency,
            $taxRate,
            $notes,
            $nextRunDate,
            $startDate,
            $endDate,
            $recurringId,
            $user['id']
        );
    }

    $update->execute();

    /*
    |--------------------------------------------------------------------------
    | Replace recurring items if supplied
    |--------------------------------------------------------------------------
    */

    if ($normalizedItems !== null) {

        $deleteItems = $db->prepare("
            DELETE FROM recurring_invoice_items
            WHERE recurring_invoice_id = ?
        ");

        $deleteItems->bind_param(
            'i',
            $recurringId
        );

        $deleteItems->execute();

        $insertItem = $db->prepare("
            INSERT INTO recurring_invoice_items
            (
                recurring_invoice_id,
                description,
                quantity,
                unit_price
            )
            VALUES (?, ?, ?, ?)
        ");

        foreach ($normalizedItems as $item) {

            $insertItem->bind_param(
                'isdd',
                $recurringId,
                $item['description'],
                $item['quantity'],
                $item['unit_price']
            );

            $insertItem->execute();
        }
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not update recurring invoice.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Return updated schedule
|--------------------------------------------------------------------------
*/

api_success([
    'recurring_invoice' => [
        'id' => $recurringId,
        'customer_id' => $customerId,
        'frequency' => $frequency,
        'tax_rate' => $taxRate,
        'notes' => $notes,
        'start_date' => $startDate,
        'next_run_date' => $nextRunDate,
        'end_date' => $endDate !== ''
            ? $endDate
            : null,
        'active' => (bool)$current['active'],
        'items_updated' =>
            $normalizedItems !== null
    ]
], 'Recurring invoice updated successfully.');

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return $d !== false
        && $d->format('Y-m-d') === $date;
}

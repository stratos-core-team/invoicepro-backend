<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

$user = require_auth_user();
$db = db();

/*
|--------------------------------------------------------------------------
| GET - List recurring invoices
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

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
            c.phone AS customer_phone

        FROM recurring_invoices r

        INNER JOIN customers c
            ON c.id = r.customer_id

        WHERE r.user_id = ?

        ORDER BY r.id DESC
    ");

    $stmt->bind_param('i', $user['id']);
    $stmt->execute();

    $schedules = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Fetch items for each schedule
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

    foreach ($schedules as &$schedule) {

        $recurringId = (int)$schedule['id'];

        $itemStmt->bind_param(
            'i',
            $recurringId
        );

        $itemStmt->execute();

        $items = $itemStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $subtotal = 0.0;

        foreach ($items as &$item) {

            $quantity =
                (float)$item['quantity'];

            $unitPrice =
                (float)$item['unit_price'];

            $lineTotal = round(
                $quantity * $unitPrice,
                2
            );

            $item['id'] =
                (int)$item['id'];

            $item['quantity'] =
                $quantity;

            $item['unit_price'] =
                $unitPrice;

            $item['line_total'] =
                $lineTotal;

            $subtotal += $lineTotal;
        }

        unset($item);

        $taxRate =
            (float)$schedule['tax_rate'];

        $taxAmount = round(
            $subtotal * ($taxRate / 100),
            2
        );

        $schedule['id'] =
            $recurringId;

        $schedule['customer_id'] =
            (int)$schedule['customer_id'];

        $schedule['tax_rate'] =
            $taxRate;

        $schedule['active'] =
            (bool)$schedule['active'];

        $schedule['items'] =
            $items;

        $schedule['subtotal'] =
            round($subtotal, 2);

        $schedule['tax_amount'] =
            $taxAmount;

        $schedule['total'] =
            round(
                $subtotal + $taxAmount,
                2
            );
    }

    unset($schedule);

    api_success([
        'recurring_invoices' => $schedules
    ]);
}


/*
|--------------------------------------------------------------------------
| POST - Create recurring invoice
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = request_json();

    $customerId = (int)(
        $input['customer_id'] ?? 0
    );

    $frequency = strtolower(
        trim(
            (string)(
                $input['frequency'] ?? ''
            )
        )
    );

    $startDate = trim(
        (string)(
            $input['start_date']
            ?? date('Y-m-d')
        )
    );

    $endDate = trim(
        (string)(
            $input['end_date'] ?? ''
        )
    );

    $taxRate = (float)(
        $input['tax_rate'] ?? 0
    );

    $notes = trim(
        (string)(
            $input['notes'] ?? ''
        )
    );

    $items =
        $input['items'] ?? [];


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

    if (!valid_date($startDate)) {

        $errors['start_date'] =
            'Start date must use YYYY-MM-DD.';
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
        && valid_date($startDate)
        && valid_date($endDate)
        && $endDate < $startDate
    ) {

        $errors['end_date'] =
            'End date cannot be earlier than start date.';
    }

    if (
        $taxRate < 0
        || $taxRate > 100
    ) {

        $errors['tax_rate'] =
            'Tax rate must be between 0 and 100.';
    }

    if (
        !is_array($items)
        || count($items) === 0
    ) {

        $errors['items'] =
            'At least one recurring invoice item is required.';
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
    | Validate invoice items
    |--------------------------------------------------------------------------
    */

    $normalizedItems = [];

    $subtotal = 0.0;

    foreach ($items as $index => $item) {

        if (!is_array($item)) {

            api_error(
                'Invalid invoice item.',
                422
            );
        }

        $description = trim(
            (string)(
                $item['description']
                ?? ''
            )
        );

        $quantity = (float)(
            $item['quantity']
            ?? 0
        );

        $unitPrice = (float)(
            $item['unit_price']
            ?? -1
        );

        if ($description === '') {

            api_error(
                'Item description is required.',
                422,
                [
                    'item' => $index
                ]
            );
        }

        if ($quantity <= 0) {

            api_error(
                'Item quantity must be greater than zero.',
                422,
                [
                    'item' => $index
                ]
            );
        }

        if ($unitPrice < 0) {

            api_error(
                'Item unit price cannot be negative.',
                422,
                [
                    'item' => $index
                ]
            );
        }

        $lineTotal = round(
            $quantity * $unitPrice,
            2
        );

        $subtotal += $lineTotal;

        $normalizedItems[] = [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal
        ];
    }

    $subtotal =
        round($subtotal, 2);

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
    | First invoice generation date
    |--------------------------------------------------------------------------
    */

    $nextRunDate =
        $startDate;


    /*
    |--------------------------------------------------------------------------
    | Database transaction
    |--------------------------------------------------------------------------
    */

    $db->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Create recurring schedule
        |--------------------------------------------------------------------------
        */

        if ($endDate === '') {

            $stmt = $db->prepare("
                INSERT INTO recurring_invoices
                (
                    user_id,
                    customer_id,
                    frequency,
                    tax_rate,
                    notes,
                    next_run_date,
                    start_date,
                    end_date,
                    active
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NULL,
                    1
                )
            ");

            $stmt->bind_param(
                'iisdsss',
                $user['id'],
                $customerId,
                $frequency,
                $taxRate,
                $notes,
                $nextRunDate,
                $startDate
            );

        } else {

            $stmt = $db->prepare("
                INSERT INTO recurring_invoices
                (
                    user_id,
                    customer_id,
                    frequency,
                    tax_rate,
                    notes,
                    next_run_date,
                    start_date,
                    end_date,
                    active
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    1
                )
            ");

            $stmt->bind_param(
                'iisdssss',
                $user['id'],
                $customerId,
                $frequency,
                $taxRate,
                $notes,
                $nextRunDate,
                $startDate,
                $endDate
            );
        }

        $stmt->execute();

        $recurringId =
            (int)$db->insert_id;


        /*
        |--------------------------------------------------------------------------
        | Save template items
        |--------------------------------------------------------------------------
        */

        $itemStmt = $db->prepare("
            INSERT INTO recurring_invoice_items
            (
                recurring_invoice_id,
                description,
                quantity,
                unit_price
            )
            VALUES (?, ?, ?, ?)
        ");

        foreach (
            $normalizedItems
            as $item
        ) {

            $itemStmt->bind_param(
                'isdd',
                $recurringId,
                $item['description'],
                $item['quantity'],
                $item['unit_price']
            );

            $itemStmt->execute();
        }

        $db->commit();

    } catch (Throwable $e) {

        $db->rollback();

        api_error(
            'Could not create recurring invoice.',
            500
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    api_success([
        'recurring_invoice' => [
            'id' =>
                $recurringId,

            'customer_id' =>
                $customerId,

            'frequency' =>
                $frequency,

            'tax_rate' =>
                $taxRate,

            'notes' =>
                $notes,

            'start_date' =>
                $startDate,

            'next_run_date' =>
                $nextRunDate,

            'end_date' =>
                $endDate !== ''
                    ? $endDate
                    : null,

            'active' =>
                true,

            'items' =>
                $normalizedItems,

            'subtotal' =>
                $subtotal,

            'tax_amount' =>
                $taxAmount,

            'total' =>
                $total
        ]
    ], 'Recurring invoice created successfully.', 201);
}


/*
|--------------------------------------------------------------------------
| Unsupported method
|--------------------------------------------------------------------------
*/

api_error(
    'Method not allowed.',
    405
);


/*
|--------------------------------------------------------------------------
| Date helper
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

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

    $stmt->bind_param(
        'i',
        $user['id']
    );

    $stmt->execute();

    $recurringInvoices = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    foreach ($recurringInvoices as &$item) {
        $item['id'] = (int)$item['id'];
        $item['customer_id'] = (int)$item['customer_id'];
        $item['active'] = (bool)$item['active'];
    }

    unset($item);

    api_success([
        'recurring_invoices' => $recurringInvoices
    ]);
}

/*
|--------------------------------------------------------------------------
| POST - Create recurring invoice schedule
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = request_json();

    $customerId = (int)(
        $input['customer_id'] ?? 0
    );

    $frequency = strtolower(
        trim(
            (string)($input['frequency'] ?? '')
        )
    );

    $startDate = trim(
        (string)(
            $input['start_date']
            ?? date('Y-m-d')
        )
    );

    $endDate = trim(
        (string)($input['end_date'] ?? '')
    );

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

    if (!$customerStmt
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
    | First run
    |--------------------------------------------------------------------------
    */

    $nextRunDate = $startDate;

    /*
    |--------------------------------------------------------------------------
    | Save recurring schedule
    |--------------------------------------------------------------------------
    */

    try {

        if ($endDate === '') {

            $stmt = $db->prepare("
                INSERT INTO recurring_invoices
                (
                    user_id,
                    customer_id,
                    frequency,
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
                    NULL,
                    1
                )
            ");

            $stmt->bind_param(
                'iisss',
                $user['id'],
                $customerId,
                $frequency,
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
                    1
                )
            ");

            $stmt->bind_param(
                'iissss',
                $user['id'],
                $customerId,
                $frequency,
                $nextRunDate,
                $startDate,
                $endDate
            );
        }

        $stmt->execute();

        $recurringId =
            (int)$db->insert_id;

    } catch (Throwable $e) {

        api_error(
            'Could not create recurring invoice schedule.',
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
            'id' => $recurringId,
            'customer_id' => $customerId,
            'frequency' => $frequency,
            'start_date' => $startDate,
            'next_run_date' => $nextRunDate,
            'end_date' =>
                $endDate !== ''
                    ? $endDate
                    : null,
            'active' => true
        ]
    ], 'Recurring invoice schedule created.', 201);
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

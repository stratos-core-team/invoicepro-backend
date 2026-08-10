<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
| This script is intended to run from CLI/cron only.
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

$db = db();

$today = date('Y-m-d');

echo "Recurring invoice scheduler started: {$today}\n";

/*
|--------------------------------------------------------------------------
| Find schedules due today or earlier
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        user_id,
        customer_id,
        frequency,
        tax_rate,
        notes,
        next_run_date,
        start_date,
        end_date,
        active

    FROM recurring_invoices

    WHERE active = 1
      AND next_run_date <= ?

    ORDER BY next_run_date ASC
");

$stmt->bind_param('s', $today);
$stmt->execute();

$schedules = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

if (!$schedules) {
    echo "No recurring invoices due.\n";
    exit(0);
}

foreach ($schedules as $schedule) {

    $recurringId = (int)$schedule['id'];
    $userId = (int)$schedule['user_id'];
    $customerId = (int)$schedule['customer_id'];

    /*
    |--------------------------------------------------------------------------
    | Stop expired schedules
    |--------------------------------------------------------------------------
    */

    if (
        !empty($schedule['end_date'])
        && $schedule['next_run_date'] > $schedule['end_date']
    ) {

        $disable = $db->prepare("
            UPDATE recurring_invoices
            SET active = 0
            WHERE id = ?
        ");

        $disable->bind_param(
            'i',
            $recurringId
        );

        $disable->execute();

        echo "Schedule {$recurringId} expired.\n";

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch recurring items
    |--------------------------------------------------------------------------
    */

    $itemStmt = $db->prepare("
        SELECT
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

    if (!$items) {

        echo "Schedule {$recurringId}: no items found.\n";

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate invoice totals
    |--------------------------------------------------------------------------
    */

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

        $item['quantity'] = $quantity;
        $item['unit_price'] = $unitPrice;
        $item['line_total'] = $lineTotal;

        $subtotal += $lineTotal;
    }

    unset($item);

    $subtotal = round(
        $subtotal,
        2
    );

    $taxRate = (float)$schedule['tax_rate'];

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
    | Invoice dates
    |--------------------------------------------------------------------------
    */

    $issueDate =
        $schedule['next_run_date'];

    /*
     * Default invoice due date = 14 days
     */

    $dueDate = date(
        'Y-m-d',
        strtotime(
            $issueDate . ' +14 days'
        )
    );

    /*
    |--------------------------------------------------------------------------
    | Generate unique invoice number
    |--------------------------------------------------------------------------
    */

    $invoiceNumber =
        'INV-' .
        date('Ymd', strtotime($issueDate))
        . '-'
        . strtoupper(
            bin2hex(
                random_bytes(3)
            )
        );

    $publicToken =
        bin2hex(
            random_bytes(24)
        );

    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    $db->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Create invoice
        |--------------------------------------------------------------------------
        */

        $invoiceStmt = $db->prepare("
            INSERT INTO invoices
            (
                user_id,
                customer_id,
                invoice_number,
                issue_date,
                due_date,
                subtotal,
                tax_rate,
                tax_amount,
                total,
                amount_paid,
                status,
                notes,
                public_token
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
                ?,
                0,
                'unpaid',
                ?,
                ?
            )
        ");

        $invoiceStmt->bind_param(
            'iisssddddss',
            $userId,
            $customerId,
            $invoiceNumber,
            $issueDate,
            $dueDate,
            $subtotal,
            $taxRate,
            $taxAmount,
            $total,
            $schedule['notes'],
            $publicToken
        );

        $invoiceStmt->execute();

        $invoiceId =
            (int)$db->insert_id;

        /*
        |--------------------------------------------------------------------------
        | Copy recurring items → invoice items
        |--------------------------------------------------------------------------
        */

        $insertItem = $db->prepare("
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

        foreach ($items as $item) {

            $insertItem->bind_param(
                'isddd',
                $invoiceId,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['line_total']
            );

            $insertItem->execute();
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate next run date
        |--------------------------------------------------------------------------
        */

        $nextRunDate =
            calculate_next_run(
                $issueDate,
                $schedule['frequency']
            );

        /*
        |--------------------------------------------------------------------------
        | Check end date
        |--------------------------------------------------------------------------
        */

        $shouldRemainActive = 1;

        if (
            !empty($schedule['end_date'])
            && $nextRunDate > $schedule['end_date']
        ) {
            $shouldRemainActive = 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Update recurring schedule
        |--------------------------------------------------------------------------
        */

        $updateSchedule = $db->prepare("
            UPDATE recurring_invoices

            SET
                next_run_date = ?,
                active = ?

            WHERE id = ?
        ");

        $updateSchedule->bind_param(
            'sii',
            $nextRunDate,
            $shouldRemainActive,
            $recurringId
        );

        $updateSchedule->execute();

        $db->commit();

        echo
            "Schedule {$recurringId}: "
            . "Invoice {$invoiceNumber} generated successfully.\n";

    } catch (Throwable $e) {

        $db->rollback();

        echo
            "Schedule {$recurringId} failed: "
            . $e->getMessage()
            . "\n";
    }
}

echo "Recurring invoice scheduler finished.\n";


/*
|--------------------------------------------------------------------------
| Calculate next run
|--------------------------------------------------------------------------
*/

function calculate_next_run(
    string $currentDate,
    string $frequency
): string {

    $date = new DateTimeImmutable(
        $currentDate
    );

    return match ($frequency) {

        'weekly' =>
            $date
                ->modify('+1 week')
                ->format('Y-m-d'),

        'monthly' =>
            $date
                ->modify('+1 month')
                ->format('Y-m-d'),

        'yearly' =>
            $date
                ->modify('+1 year')
                ->format('Y-m-d'),

        default =>
            throw new RuntimeException(
                'Invalid recurring frequency.'
            )
    };
}

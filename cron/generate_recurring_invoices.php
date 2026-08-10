<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
| Script hii imekusudiwa kuendeshwa kupitia CLI / Cron pekee.
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

$db = db();

$today = date('Y-m-d');

echo "============================================\n";
echo "InvoicePro NG Recurring Invoice Scheduler\n";
echo "Date: {$today}\n";
echo "============================================\n";

/*
|--------------------------------------------------------------------------
| Find active schedules that are due
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

    ORDER BY next_run_date ASC, id ASC
");

$stmt->bind_param(
    's',
    $today
);

$stmt->execute();

$schedules = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

if (!$schedules) {

    echo "No recurring invoices due.\n";
    exit(0);
}

/*
|--------------------------------------------------------------------------
| Process schedules
|--------------------------------------------------------------------------
*/

foreach ($schedules as $schedule) {

    $recurringId =
        (int)$schedule['id'];

    $userId =
        (int)$schedule['user_id'];

    $customerId =
        (int)$schedule['customer_id'];

    $issueDate =
        (string)$schedule['next_run_date'];

    echo "\n--------------------------------------------\n";
    echo "Processing schedule #{$recurringId}\n";
    echo "Issue date: {$issueDate}\n";

    /*
    |--------------------------------------------------------------------------
    | Check whether schedule has expired
    |--------------------------------------------------------------------------
    */

    if (
        !empty($schedule['end_date'])
        && $issueDate > $schedule['end_date']
    ) {

        disable_schedule(
            $db,
            $recurringId
        );

        echo "Schedule expired and was disabled.\n";

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate protection
    |--------------------------------------------------------------------------
    |
    | Do not generate another invoice if this recurring schedule already
    | generated an invoice for the same issue date.
    |--------------------------------------------------------------------------
    */

    $duplicateStmt = $db->prepare("
        SELECT id, invoice_number
        FROM invoices
        WHERE recurring_invoice_id = ?
          AND issue_date = ?
        LIMIT 1
    ");

    $duplicateStmt->bind_param(
        'is',
        $recurringId,
        $issueDate
    );

    $duplicateStmt->execute();

    $existingInvoice = $duplicateStmt
        ->get_result()
        ->fetch_assoc();

    if ($existingInvoice) {

        echo
            "Duplicate skipped. Existing invoice: "
            . $existingInvoice['invoice_number']
            . "\n";

        /*
        |--------------------------------------------------------------------------
        | Move schedule forward
        |--------------------------------------------------------------------------
        |
        | This is useful if an invoice was generated successfully previously
        | but next_run_date did not get updated for some reason.
        |--------------------------------------------------------------------------
        */

        try {

            $nextRunDate = calculate_next_run(
                $issueDate,
                $schedule['frequency']
            );

            $active = should_remain_active(
                $nextRunDate,
                $schedule['end_date']
            );

            update_schedule(
                $db,
                $recurringId,
                $nextRunDate,
                $active
            );

            echo "Schedule advanced to {$nextRunDate}.\n";

        } catch (Throwable $e) {

            echo
                "Could not advance schedule: "
                . $e->getMessage()
                . "\n";
        }

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch recurring invoice items
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

    if (!$items) {

        echo
            "Schedule #{$recurringId} has no invoice items. "
            . "Invoice not generated.\n";

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate subtotal
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

        $item['quantity'] =
            $quantity;

        $item['unit_price'] =
            $unitPrice;

        $item['line_total'] =
            $lineTotal;

        $subtotal +=
            $lineTotal;
    }

    unset($item);

    $subtotal = round(
        $subtotal,
        2
    );

    /*
    |--------------------------------------------------------------------------
    | Tax calculation
    |--------------------------------------------------------------------------
    */

    $taxRate = (float)(
        $schedule['tax_rate']
        ?? 0
    );

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
    | Due date
    |--------------------------------------------------------------------------
    |
    | PRD default = 14 days.
    |--------------------------------------------------------------------------
    */

    $dueDate = date(
        'Y-m-d',
        strtotime(
            $issueDate . ' +14 days'
        )
    );

    /*
    |--------------------------------------------------------------------------
    | Invoice identifiers
    |--------------------------------------------------------------------------
    */

    $invoiceNumber =
        'INV-'
        . date(
            'Ymd',
            strtotime($issueDate)
        )
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
    | Generate invoice transaction
    |--------------------------------------------------------------------------
    */

    $db->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Re-check duplicate inside transaction
        |--------------------------------------------------------------------------
        */

        $duplicateCheck = $db->prepare("
            SELECT id
            FROM invoices
            WHERE recurring_invoice_id = ?
              AND issue_date = ?
            LIMIT 1
        ");

        $duplicateCheck->bind_param(
            'is',
            $recurringId,
            $issueDate
        );

        $duplicateCheck->execute();

        if (
            $duplicateCheck
                ->get_result()
                ->fetch_assoc()
        ) {

            $db->rollback();

            echo
                "Invoice already generated by another scheduler process. "
                . "Skipped.\n";

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Insert invoice
        |--------------------------------------------------------------------------
        */

        $invoiceStmt = $db->prepare("
            INSERT INTO invoices
            (
                user_id,
                customer_id,
                recurring_invoice_id,
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
                ?,
                0,
                'unpaid',
                ?,
                ?
            )
        ");

        $notes =
            (string)(
                $schedule['notes']
                ?? ''
            );

        /*
        |--------------------------------------------------------------------------
        | Parameter types
        |--------------------------------------------------------------------------
        |
        | i = user_id
        | i = customer_id
        | i = recurring_invoice_id
        | s = invoice_number
        | s = issue_date
        | s = due_date
        | d = subtotal
        | d = tax_rate
        | d = tax_amount
        | d = total
        | s = notes
        | s = public_token
        |--------------------------------------------------------------------------
        */

        $invoiceStmt->bind_param(
            'iiisssddddss',
            $userId,
            $customerId,
            $recurringId,
            $invoiceNumber,
            $issueDate,
            $dueDate,
            $subtotal,
            $taxRate,
            $taxAmount,
            $total,
            $notes,
            $publicToken
        );

        $invoiceStmt->execute();

        $invoiceId =
            (int)$db->insert_id;

        /*
        |--------------------------------------------------------------------------
        | Copy recurring items to invoice_items
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

            $description =
                (string)$item['description'];

            $quantity =
                (float)$item['quantity'];

            $unitPrice =
                (float)$item['unit_price'];

            $lineTotal =
                (float)$item['line_total'];

            $insertItem->bind_param(
                'isddd',
                $invoiceId,
                $description,
                $quantity,
                $unitPrice,
                $lineTotal
            );

            $insertItem->execute();
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate next run date
        |--------------------------------------------------------------------------
        */

        $nextRunDate = calculate_next_run(
            $issueDate,
            $schedule['frequency']
        );

        /*
        |--------------------------------------------------------------------------
        | Determine if schedule should remain active
        |--------------------------------------------------------------------------
        */

        $active = should_remain_active(
            $nextRunDate,
            $schedule['end_date']
        );

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
            $active,
            $recurringId
        );

        $updateSchedule->execute();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */

        $db->commit();

        echo "Invoice generated successfully.\n";
        echo "Invoice ID: {$invoiceId}\n";
        echo "Invoice number: {$invoiceNumber}\n";
        echo "Subtotal: {$subtotal}\n";
        echo "Tax: {$taxAmount}\n";
        echo "Total: {$total}\n";
        echo "Next run: {$nextRunDate}\n";

        if ($active === 0) {
            echo "Schedule completed and is now inactive.\n";
        }

    } catch (Throwable $e) {

        $db->rollback();

        echo
            "Schedule #{$recurringId} failed: "
            . $e->getMessage()
            . "\n";
    }
}

echo "\n============================================\n";
echo "Recurring invoice scheduler finished.\n";
echo "============================================\n";


/*
|--------------------------------------------------------------------------
| Calculate next run date
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
            safe_add_month(
                $date
            ),

        'yearly' =>
            $date
                ->modify('+1 year')
                ->format('Y-m-d'),

        default =>
            throw new RuntimeException(
                'Invalid recurring frequency: '
                . $frequency
            )
    };
}


/*
|--------------------------------------------------------------------------
| Safer monthly calculation
|--------------------------------------------------------------------------
|
| PHP "+1 month" inaweza kutoa matokeo yasiyotarajiwa kwa tarehe kama
| January 31. Hii inalinda recurring billing isiruke mwezi.
|--------------------------------------------------------------------------
*/

function safe_add_month(
    DateTimeImmutable $date
): string {

    $day =
        (int)$date->format('d');

    $firstOfNextMonth =
        $date
            ->modify('first day of next month');

    $daysInNextMonth =
        (int)$firstOfNextMonth
            ->format('t');

    $targetDay =
        min(
            $day,
            $daysInNextMonth
        );

    return $firstOfNextMonth
        ->setDate(
            (int)$firstOfNextMonth
                ->format('Y'),

            (int)$firstOfNextMonth
                ->format('m'),

            $targetDay
        )
        ->format('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| Determine whether schedule stays active
|--------------------------------------------------------------------------
*/

function should_remain_active(
    string $nextRunDate,
    ?string $endDate
): int {

    if (
        $endDate === null
        || $endDate === ''
    ) {
        return 1;
    }

    return $nextRunDate <= $endDate
        ? 1
        : 0;
}


/*
|--------------------------------------------------------------------------
| Update recurring schedule
|--------------------------------------------------------------------------
*/

function update_schedule(
    mysqli $db,
    int $recurringId,
    string $nextRunDate,
    int $active
): void {

    $stmt = $db->prepare("
        UPDATE recurring_invoices

        SET
            next_run_date = ?,
            active = ?

        WHERE id = ?
    ");

    $stmt->bind_param(
        'sii',
        $nextRunDate,
        $active,
        $recurringId
    );

    $stmt->execute();
}


/*
|--------------------------------------------------------------------------
| Disable schedule
|--------------------------------------------------------------------------
*/

function disable_schedule(
    mysqli $db,
    int $recurringId
): void {

    $stmt = $db->prepare("
        UPDATE recurring_invoices
        SET active = 0
        WHERE id = ?
    ");

    $stmt->bind_param(
        'i',
        $recurringId
    );

    $stmt->execute();
}

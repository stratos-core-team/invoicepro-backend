<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH'], true)) {
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
| Find recurring schedule
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        frequency,
        next_run_date,
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
| Read action
|--------------------------------------------------------------------------
|
| Supported:
|
| action = pause
| action = resume
|
*/

$input = request_json();

$action = strtolower(
    trim(
        (string)($input['action'] ?? '')
    )
);

if (!in_array(
    $action,
    ['pause', 'resume'],
    true
)) {
    api_error(
        'Action must be pause or resume.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| PAUSE
|--------------------------------------------------------------------------
*/

if ($action === 'pause') {

    if ((int)$recurring['active'] === 0) {

        api_success([
            'recurring_invoice' => [
                'id' => $recurringId,
                'active' => false,
                'next_run_date' =>
                    $recurring['next_run_date']
            ]
        ], 'Recurring invoice is already paused.');
    }

    $update = $db->prepare("
        UPDATE recurring_invoices

        SET active = 0

        WHERE id = ?
          AND user_id = ?
    ");

    $update->bind_param(
        'ii',
        $recurringId,
        $user['id']
    );

    $update->execute();

    api_success([
        'recurring_invoice' => [
            'id' => $recurringId,
            'active' => false,
            'next_run_date' =>
                $recurring['next_run_date']
        ]
    ], 'Recurring invoice paused successfully.');
}

/*
|--------------------------------------------------------------------------
| RESUME
|--------------------------------------------------------------------------
*/

if ($action === 'resume') {

    if ((int)$recurring['active'] === 1) {

        api_success([
            'recurring_invoice' => [
                'id' => $recurringId,
                'active' => true,
                'next_run_date' =>
                    $recurring['next_run_date']
            ]
        ], 'Recurring invoice is already active.');
    }

    /*
    |--------------------------------------------------------------------------
    | Determine next run date
    |--------------------------------------------------------------------------
    |
    | If next_run_date passed while the schedule was paused,
    | we do NOT generate all missed invoices.
    |
    | Instead, move the next run forward until it is today or future.
    |--------------------------------------------------------------------------
    */

    $today = date('Y-m-d');

    $nextRunDate =
        (string)$recurring['next_run_date'];

    while ($nextRunDate < $today) {

        $nextRunDate = calculate_next_run(
            $nextRunDate,
            $recurring['frequency']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check end date
    |--------------------------------------------------------------------------
    */

    if (
        !empty($recurring['end_date'])
        && $nextRunDate > $recurring['end_date']
    ) {

        api_error(
            'This recurring invoice has already reached its end date.',
            409
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Resume schedule
    |--------------------------------------------------------------------------
    */

    $update = $db->prepare("
        UPDATE recurring_invoices

        SET
            active = 1,
            next_run_date = ?

        WHERE id = ?
          AND user_id = ?
    ");

    $update->bind_param(
        'sii',
        $nextRunDate,
        $recurringId,
        $user['id']
    );

    $update->execute();

    api_success([
        'recurring_invoice' => [
            'id' => $recurringId,
            'active' => true,
            'next_run_date' =>
                $nextRunDate
        ]
    ], 'Recurring invoice resumed successfully.');
}

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
            safe_add_month($date),

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

/*
|--------------------------------------------------------------------------
| Safe monthly calculation
|--------------------------------------------------------------------------
*/

function safe_add_month(
    DateTimeImmutable $date
): string {

    $day =
        (int)$date->format('d');

    $firstOfNextMonth =
        $date->modify(
            'first day of next month'
        );

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

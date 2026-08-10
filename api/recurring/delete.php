<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
| Find recurring schedule and verify ownership
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        r.id,
        r.customer_id,
        r.frequency,
        r.next_run_date,
        r.active,
        r.created_at,

        c.name AS customer_name

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
| Count invoices already generated
|--------------------------------------------------------------------------
|
| We keep these invoices as accounting records.
| Because invoices.recurring_invoice_id uses ON DELETE SET NULL,
| deleting the schedule will NOT delete historical invoices.
|--------------------------------------------------------------------------
*/

$invoiceStmt = $db->prepare("
    SELECT COUNT(*) AS total
    FROM invoices
    WHERE recurring_invoice_id = ?
      AND user_id = ?
");

$invoiceStmt->bind_param(
    'ii',
    $recurringId,
    $user['id']
);

$invoiceStmt->execute();

$generatedInvoices = (int)(
    $invoiceStmt
        ->get_result()
        ->fetch_assoc()['total']
    ?? 0
);

/*
|--------------------------------------------------------------------------
| Delete recurring schedule
|--------------------------------------------------------------------------
|
| recurring_invoice_items are deleted automatically through:
|
| ON DELETE CASCADE
|
| Historical invoices remain because invoices use:
|
| ON DELETE SET NULL
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    $delete = $db->prepare("
        DELETE FROM recurring_invoices
        WHERE id = ?
          AND user_id = ?
    ");

    $delete->bind_param(
        'ii',
        $recurringId,
        $user['id']
    );

    $delete->execute();

    if ($delete->affected_rows !== 1) {
        throw new RuntimeException(
            'Recurring invoice could not be deleted.'
        );
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not delete recurring invoice.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'deleted_recurring_invoice' => [
        'id' => $recurringId,

        'customer_id' =>
            (int)$recurring['customer_id'],

        'customer_name' =>
            $recurring['customer_name'],

        'frequency' =>
            $recurring['frequency'],

        'generated_invoices_preserved' =>
            $generatedInvoices
    ]
], 'Recurring invoice deleted successfully.');

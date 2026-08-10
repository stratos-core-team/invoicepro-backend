<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
| Find invoice and verify ownership
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        invoice_number,
        status,
        amount_paid
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
| Protect paid invoices
|--------------------------------------------------------------------------
|
| Paid invoices should normally remain in the system for accounting
| history instead of being permanently deleted.
|
*/

if ($invoice['status'] === 'paid') {
    api_error(
        'Paid invoices cannot be deleted.',
        409
    );
}

if ((float)$invoice['amount_paid'] > 0) {
    api_error(
        'Invoices with recorded payments cannot be deleted.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| Delete invoice
|--------------------------------------------------------------------------
|
| invoice_items are removed automatically because the database
| foreign key uses ON DELETE CASCADE.
|
*/

$db->begin_transaction();

try {

    $delete = $db->prepare("
        DELETE FROM invoices
        WHERE id = ?
          AND user_id = ?
    ");

    $delete->bind_param(
        'ii',
        $invoiceId,
        $user['id']
    );

    $delete->execute();

    if ($delete->affected_rows !== 1) {
        throw new RuntimeException(
            'Invoice could not be deleted.'
        );
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not delete invoice.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'deleted_invoice' => [
        'id' => $invoiceId,
        'invoice_number' => $invoice['invoice_number']
    ]
], 'Invoice deleted successfully.');
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$customerId = (int)($_GET['id'] ?? 0);

if ($customerId <= 0) {
    api_error(
        'A valid customer id is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Find customer and verify ownership
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        name,
        email
    FROM customers
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $customerId,
    $user['id']
);

$stmt->execute();

$customer = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$customer) {
    api_error(
        'Customer not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Check whether customer has invoices
|--------------------------------------------------------------------------
|
| We should not delete customers with existing invoices because those
| invoices are part of the business accounting history.
|
*/

$invoiceStmt = $db->prepare("
    SELECT COUNT(*) AS total
    FROM invoices
    WHERE customer_id = ?
      AND user_id = ?
");

$invoiceStmt->bind_param(
    'ii',
    $customerId,
    $user['id']
);

$invoiceStmt->execute();

$invoiceCount = (int)(
    $invoiceStmt
        ->get_result()
        ->fetch_assoc()['total']
        ?? 0
);

if ($invoiceCount > 0) {

    api_error(
        'Customer cannot be deleted because they have existing invoices.',
        409,
        [
            'invoice_count' => $invoiceCount
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Delete customer
|--------------------------------------------------------------------------
*/

try {

    $delete = $db->prepare("
        DELETE FROM customers
        WHERE id = ?
          AND user_id = ?
    ");

    $delete->bind_param(
        'ii',
        $customerId,
        $user['id']
    );

    $delete->execute();

    if ($delete->affected_rows !== 1) {
        api_error(
            'Customer could not be deleted.',
            500
        );
    }

} catch (Throwable $e) {

    api_error(
        'Could not delete customer.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'deleted_customer' => [
        'id' => $customerId,
        'name' => $customer['name'],
        'email' => $customer['email']
    ]
], 'Customer deleted successfully.');
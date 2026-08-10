<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$expenseId = (int)($_GET['id'] ?? 0);

if ($expenseId <= 0) {
    api_error(
        'A valid expense id is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Find expense and verify ownership
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        title,
        amount,
        expense_date
    FROM expenses
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $expenseId,
    $user['id']
);

$stmt->execute();

$expense = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$expense) {
    api_error(
        'Expense not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Delete expense
|--------------------------------------------------------------------------
*/

try {

    $delete = $db->prepare("
        DELETE FROM expenses
        WHERE id = ?
          AND user_id = ?
    ");

    $delete->bind_param(
        'ii',
        $expenseId,
        $user['id']
    );

    $delete->execute();

    if ($delete->affected_rows !== 1) {
        api_error(
            'Expense could not be deleted.',
            500
        );
    }

} catch (Throwable $e) {

    api_error(
        'Could not delete expense.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'deleted_expense' => [
        'id' => $expenseId,
        'title' => $expense['title'],
        'amount' => (float)$expense['amount'],
        'expense_date' => $expense['expense_date']
    ]
], 'Expense deleted successfully.');

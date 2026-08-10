<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
| Fetch expense and verify ownership
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        title,
        description,
        amount,
        tax_rate,
        expense_date,
        category,
        created_at
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
| Calculate tax amount
|--------------------------------------------------------------------------
*/

$amount = (float)$expense['amount'];
$taxRate = (float)$expense['tax_rate'];

$taxAmount = round(
    $amount * ($taxRate / 100),
    2
);

$expense['amount'] = $amount;
$expense['tax_rate'] = $taxRate;
$expense['tax_amount'] = $taxAmount;

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'expense' => $expense
]);

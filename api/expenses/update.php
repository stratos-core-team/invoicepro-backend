<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
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
        description,
        amount,
        tax_rate,
        expense_date,
        category
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
| Read JSON input
|--------------------------------------------------------------------------
*/

$input = request_json();

/*
|--------------------------------------------------------------------------
| Existing values remain when not supplied
|--------------------------------------------------------------------------
*/

$title = array_key_exists('title', $input)
    ? trim((string)$input['title'])
    : (string)$expense['title'];

$description = array_key_exists('description', $input)
    ? trim((string)$input['description'])
    : (string)($expense['description'] ?? '');

$amount = array_key_exists('amount', $input)
    ? (float)$input['amount']
    : (float)$expense['amount'];

$taxRate = array_key_exists('tax_rate', $input)
    ? (float)$input['tax_rate']
    : (float)$expense['tax_rate'];

$expenseDate = array_key_exists('expense_date', $input)
    ? trim((string)$input['expense_date'])
    : (string)$expense['expense_date'];

$category = array_key_exists('category', $input)
    ? trim((string)$input['category'])
    : (string)($expense['category'] ?? '');

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

$errors = [];

if ($title === '') {
    $errors['title'] =
        'Expense title is required.';
}

if (strlen($title) > 160) {
    $errors['title'] =
        'Expense title is too long.';
}

if ($amount <= 0) {
    $errors['amount'] =
        'Expense amount must be greater than zero.';
}

if ($taxRate < 0 || $taxRate > 100) {
    $errors['tax_rate'] =
        'Tax rate must be between 0 and 100.';
}

if (!valid_date($expenseDate)) {
    $errors['expense_date'] =
        'Expense date must use YYYY-MM-DD.';
}

if (strlen($category) > 100) {
    $errors['category'] =
        'Category is too long.';
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
| Update expense
|--------------------------------------------------------------------------
*/

try {

    $update = $db->prepare("
        UPDATE expenses
        SET
            title = ?,
            description = ?,
            amount = ?,
            tax_rate = ?,
            expense_date = ?,
            category = ?
        WHERE id = ?
          AND user_id = ?
    ");

    $update->bind_param(
        'ssddssii',
        $title,
        $description,
        $amount,
        $taxRate,
        $expenseDate,
        $category,
        $expenseId,
        $user['id']
    );

    $update->execute();

} catch (Throwable $e) {

    api_error(
        'Could not update expense.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Calculate tax amount
|--------------------------------------------------------------------------
*/

$taxAmount = round(
    $amount * ($taxRate / 100),
    2
);

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'expense' => [
        'id' => $expenseId,
        'title' => $title,
        'description' => $description,
        'amount' => $amount,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'expense_date' => $expenseDate,
        'category' => $category
    ]
], 'Expense updated successfully.');

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

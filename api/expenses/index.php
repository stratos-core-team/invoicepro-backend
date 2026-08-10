<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

$user = require_auth_user();
$db = db();

/*
|--------------------------------------------------------------------------
| GET - List expenses
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $category = trim((string)($_GET['category'] ?? ''));
    $fromDate = trim((string)($_GET['from'] ?? ''));
    $toDate   = trim((string)($_GET['to'] ?? ''));

    $sql = "
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
        WHERE user_id = ?
    ";

    $types = 'i';
    $params = [$user['id']];

    /*
    |--------------------------------------------------------------------------
    | Optional category filter
    |--------------------------------------------------------------------------
    */

    if ($category !== '') {
        $sql .= " AND category = ?";
        $types .= 's';
        $params[] = $category;
    }

    /*
    |--------------------------------------------------------------------------
    | Optional date filters
    |--------------------------------------------------------------------------
    */

    if ($fromDate !== '') {

        if (!valid_date($fromDate)) {
            api_error(
                'Invalid from date. Use YYYY-MM-DD.',
                422
            );
        }

        $sql .= " AND expense_date >= ?";
        $types .= 's';
        $params[] = $fromDate;
    }

    if ($toDate !== '') {

        if (!valid_date($toDate)) {
            api_error(
                'Invalid to date. Use YYYY-MM-DD.',
                422
            );
        }

        $sql .= " AND expense_date <= ?";
        $types .= 's';
        $params[] = $toDate;
    }

    $sql .= " ORDER BY expense_date DESC, id DESC";

    $stmt = $db->prepare($sql);

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $expenses = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Calculate summary
    |--------------------------------------------------------------------------
    */

    $totalExpenses = 0.0;
    $totalTax = 0.0;

    foreach ($expenses as &$expense) {

        $amount = (float)$expense['amount'];
        $taxRate = (float)$expense['tax_rate'];

        $taxAmount = round(
            $amount * ($taxRate / 100),
            2
        );

        $expense['tax_amount'] = $taxAmount;

        $totalExpenses += $amount;
        $totalTax += $taxAmount;
    }

    unset($expense);

    api_success([
        'expenses' => $expenses,

        'summary' => [
            'count' => count($expenses),

            'total_expenses' => round(
                $totalExpenses,
                2
            ),

            'total_tax' => round(
                $totalTax,
                2
            )
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| POST - Create expense
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = request_json();

    $title = trim(
        (string)($input['title'] ?? '')
    );

    $description = trim(
        (string)($input['description'] ?? '')
    );

    $amount = (float)(
        $input['amount'] ?? 0
    );

    $taxRate = (float)(
        $input['tax_rate'] ?? 0
    );

    $expenseDate = trim(
        (string)(
            $input['expense_date']
            ?? date('Y-m-d')
        )
    );

    $category = trim(
        (string)($input['category'] ?? '')
    );

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
    | Insert expense
    |--------------------------------------------------------------------------
    */

    try {

        $stmt = $db->prepare("
            INSERT INTO expenses
            (
                user_id,
                title,
                description,
                amount,
                tax_rate,
                expense_date,
                category
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'issddss',
            $user['id'],
            $title,
            $description,
            $amount,
            $taxRate,
            $expenseDate,
            $category
        );

        $stmt->execute();

        $expenseId = (int)$db->insert_id;

    } catch (Throwable $e) {

        api_error(
            'Could not create expense.',
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate tax
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
    ], 'Expense created successfully.', 201);
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
| Date validation helper
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$userId = (int)$user['id'];

/*
|--------------------------------------------------------------------------
| Range
|--------------------------------------------------------------------------
| Default = last 12 months
|--------------------------------------------------------------------------
*/

$months = (int)($_GET['months'] ?? 12);

if ($months < 1 || $months > 24) {
    api_error(
        'months must be between 1 and 24.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Build month keys
|--------------------------------------------------------------------------
*/

$periods = [];

$current = new DateTimeImmutable(
    date('Y-m-01')
);

for ($i = $months - 1; $i >= 0; $i--) {

    $date = $current->modify(
        "-{$i} months"
    );

    $key = $date->format('Y-m');

    $periods[$key] = [
        'month' => $key,
        'label' => $date->format('M Y'),
        'invoices_created' => 0,
        'invoiced_amount' => 0.0,
        'received_amount' => 0.0,
        'expenses' => 0.0,
        'expense_tax' => 0.0,
        'paid_invoices' => 0,
        'unpaid_invoices' => 0
    ];
}

/*
|--------------------------------------------------------------------------
| Date range
|--------------------------------------------------------------------------
*/

$firstKey = array_key_first($periods);
$lastKey = array_key_last($periods);

$fromDate =
    $firstKey . '-01';

$lastMonth = new DateTimeImmutable(
    $lastKey . '-01'
);

$toDate =
    $lastMonth
        ->modify('last day of this month')
        ->format('Y-m-d');

/*
|--------------------------------------------------------------------------
| Invoice trends
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        DATE_FORMAT(issue_date, '%Y-%m') AS period,

        COUNT(*) AS invoices_created,

        COALESCE(SUM(total), 0)
            AS invoiced_amount,

        COALESCE(SUM(amount_paid), 0)
            AS received_amount,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'paid'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS paid_invoices,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'unpaid'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS unpaid_invoices

    FROM invoices

    WHERE user_id = ?
      AND issue_date BETWEEN ? AND ?
      AND status <> 'cancelled'

    GROUP BY
        DATE_FORMAT(issue_date, '%Y-%m')

    ORDER BY period ASC
");

$stmt->bind_param(
    'iss',
    $userId,
    $fromDate,
    $toDate
);

$stmt->execute();

$invoiceRows = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

foreach ($invoiceRows as $row) {

    $key =
        (string)$row['period'];

    if (!isset($periods[$key])) {
        continue;
    }

    $periods[$key]['invoices_created'] =
        (int)$row['invoices_created'];

    $periods[$key]['invoiced_amount'] =
        round(
            (float)$row['invoiced_amount'],
            2
        );

    $periods[$key]['received_amount'] =
        round(
            (float)$row['received_amount'],
            2
        );

    $periods[$key]['paid_invoices'] =
        (int)$row['paid_invoices'];

    $periods[$key]['unpaid_invoices'] =
        (int)$row['unpaid_invoices'];
}

/*
|--------------------------------------------------------------------------
| Expense trends
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        DATE_FORMAT(
            expense_date,
            '%Y-%m'
        ) AS period,

        COALESCE(
            SUM(amount),
            0
        ) AS expenses,

        COALESCE(
            SUM(
                amount * (tax_rate / 100)
            ),
            0
        ) AS expense_tax

    FROM expenses

    WHERE user_id = ?
      AND expense_date BETWEEN ? AND ?

    GROUP BY
        DATE_FORMAT(
            expense_date,
            '%Y-%m'
        )

    ORDER BY period ASC
");

$stmt->bind_param(
    'iss',
    $userId,
    $fromDate,
    $toDate
);

$stmt->execute();

$expenseRows = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

foreach ($expenseRows as $row) {

    $key =
        (string)$row['period'];

    if (!isset($periods[$key])) {
        continue;
    }

    $periods[$key]['expenses'] =
        round(
            (float)$row['expenses'],
            2
        );

    $periods[$key]['expense_tax'] =
        round(
            (float)$row['expense_tax'],
            2
        );
}

/*
|--------------------------------------------------------------------------
| Calculate monthly cash position
|--------------------------------------------------------------------------
*/

foreach ($periods as &$period) {

    $period['cash_position'] = round(
        $period['received_amount']
        - $period['expenses'],
        2
    );
}

unset($period);

/*
|--------------------------------------------------------------------------
| Expense categories
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        COALESCE(
            NULLIF(category, ''),
            'Uncategorized'
        ) AS category,

        COUNT(*) AS entries,

        COALESCE(
            SUM(amount),
            0
        ) AS amount

    FROM expenses

    WHERE user_id = ?
      AND expense_date BETWEEN ? AND ?

    GROUP BY
        COALESCE(
            NULLIF(category, ''),
            'Uncategorized'
        )

    ORDER BY amount DESC
");

$stmt->bind_param(
    'iss',
    $userId,
    $fromDate,
    $toDate
);

$stmt->execute();

$categories = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

foreach ($categories as &$category) {

    $category['entries'] =
        (int)$category['entries'];

    $category['amount'] =
        round(
            (float)$category['amount'],
            2
        );
}

unset($category);

/*
|--------------------------------------------------------------------------
| Top customers
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        c.id,
        c.name,

        COUNT(i.id)
            AS invoice_count,

        COALESCE(
            SUM(i.total),
            0
        ) AS total_invoiced,

        COALESCE(
            SUM(i.amount_paid),
            0
        ) AS total_received

    FROM customers c

    INNER JOIN invoices i
        ON i.customer_id = c.id

    WHERE c.user_id = ?
      AND i.issue_date BETWEEN ? AND ?
      AND i.status <> 'cancelled'

    GROUP BY
        c.id,
        c.name

    ORDER BY total_received DESC

    LIMIT 10
");

$stmt->bind_param(
    'iss',
    $userId,
    $fromDate,
    $toDate
);

$stmt->execute();

$topCustomers = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

foreach ($topCustomers as &$customer) {

    $customer['id'] =
        (int)$customer['id'];

    $customer['invoice_count'] =
        (int)$customer['invoice_count'];

    $customer['total_invoiced'] =
        round(
            (float)$customer['total_invoiced'],
            2
        );

    $customer['total_received'] =
        round(
            (float)$customer['total_received'],
            2
        );
}

unset($customer);

/*
|--------------------------------------------------------------------------
| Totals for requested range
|--------------------------------------------------------------------------
*/

$totals = [
    'invoices_created' => 0,
    'invoiced_amount' => 0.0,
    'received_amount' => 0.0,
    'expenses' => 0.0,
    'expense_tax' => 0.0,
    'cash_position' => 0.0
];

foreach ($periods as $period) {

    $totals['invoices_created'] +=
        $period['invoices_created'];

    $totals['invoiced_amount'] +=
        $period['invoiced_amount'];

    $totals['received_amount'] +=
        $period['received_amount'];

    $totals['expenses'] +=
        $period['expenses'];

    $totals['expense_tax'] +=
        $period['expense_tax'];
}

$totals['invoiced_amount'] =
    round(
        $totals['invoiced_amount'],
        2
    );

$totals['received_amount'] =
    round(
        $totals['received_amount'],
        2
    );

$totals['expenses'] =
    round(
        $totals['expenses'],
        2
    );

$totals['expense_tax'] =
    round(
        $totals['expense_tax'],
        2
    );

$totals['cash_position'] =
    round(
        $totals['received_amount']
        - $totals['expenses'],
        2
    );

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'range' => [
        'months' => $months,
        'from' => $fromDate,
        'to' => $toDate
    ],

    'totals' =>
        $totals,

    'monthly' =>
        array_values($periods),

    'expense_categories' =>
        $categories,

    'top_customers' =>
        $topCustomers
]);

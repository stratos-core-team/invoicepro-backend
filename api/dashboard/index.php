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
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

/*
|--------------------------------------------------------------------------
| Invoice summary
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total_invoices,

        COALESCE(SUM(total), 0) AS total_invoiced,

        COALESCE(SUM(amount_paid), 0) AS total_received,

        COALESCE(
            SUM(
                CASE
                    WHEN status <> 'cancelled'
                    THEN GREATEST(total - amount_paid, 0)
                    ELSE 0
                END
            ),
            0
        ) AS outstanding_balance,

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
        ) AS unpaid_invoices,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'unpaid'
                     AND due_date < ?
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS overdue_invoices

    FROM invoices

    WHERE user_id = ?
");

$stmt->bind_param(
    'si',
    $today,
    $userId
);

$stmt->execute();

$invoiceSummary = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Current month invoice/revenue summary
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS invoices_created,

        COALESCE(SUM(total), 0)
            AS invoiced_amount,

        COALESCE(SUM(amount_paid), 0)
            AS received_amount

    FROM invoices

    WHERE user_id = ?
      AND issue_date BETWEEN ? AND ?
      AND status <> 'cancelled'
");

$stmt->bind_param(
    'iss',
    $userId,
    $monthStart,
    $monthEnd
);

$stmt->execute();

$monthlyInvoices = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Expense summary
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total_expenses_count,

        COALESCE(SUM(amount), 0)
            AS total_expenses,

        COALESCE(
            SUM(
                amount * (tax_rate / 100)
            ),
            0
        ) AS total_expense_tax

    FROM expenses

    WHERE user_id = ?
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$expenseSummary = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Current month expenses
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS count,

        COALESCE(SUM(amount), 0)
            AS amount,

        COALESCE(
            SUM(
                amount * (tax_rate / 100)
            ),
            0
        ) AS tax

    FROM expenses

    WHERE user_id = ?
      AND expense_date BETWEEN ? AND ?
");

$stmt->bind_param(
    'iss',
    $userId,
    $monthStart,
    $monthEnd
);

$stmt->execute();

$monthlyExpenses = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Customer count
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT COUNT(*) AS total
    FROM customers
    WHERE user_id = ?
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$customerCount = (int)(
    $stmt
        ->get_result()
        ->fetch_assoc()['total']
    ?? 0
);

/*
|--------------------------------------------------------------------------
| Recurring invoice summary
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total,

        COALESCE(
            SUM(
                CASE
                    WHEN active = 1
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS active,

        COALESCE(
            SUM(
                CASE
                    WHEN active = 0
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS inactive

    FROM recurring_invoices

    WHERE user_id = ?
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$recurringSummary = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Successful payment summary
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total_payments,

        COALESCE(SUM(p.amount), 0)
            AS payment_volume

    FROM payments p

    INNER JOIN invoices i
        ON i.id = p.invoice_id

    WHERE i.user_id = ?
      AND p.status = 'successful'
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$paymentSummary = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Recent invoices
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.issue_date,
        i.due_date,
        i.total,
        i.amount_paid,
        i.status,

        c.id AS customer_id,
        c.name AS customer_name

    FROM invoices i

    INNER JOIN customers c
        ON c.id = i.customer_id

    WHERE i.user_id = ?

    ORDER BY i.id DESC

    LIMIT 5
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$recentInvoices = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

foreach ($recentInvoices as &$invoice) {

    $invoice['id'] =
        (int)$invoice['id'];

    $invoice['customer_id'] =
        (int)$invoice['customer_id'];

    $invoice['total'] =
        (float)$invoice['total'];

    $invoice['amount_paid'] =
        (float)$invoice['amount_paid'];

    $invoice['balance'] = max(
        0,
        round(
            $invoice['total']
            - $invoice['amount_paid'],
            2
        )
    );

    $invoice['display_status'] =
        $invoice['status'];

    if (
        $invoice['status'] === 'unpaid'
        && $invoice['due_date'] < $today
    ) {
        $invoice['display_status'] =
            'overdue';
    }
}

unset($invoice);

/*
|--------------------------------------------------------------------------
| Recent payments
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        p.id,
        p.reference,
        p.amount,
        p.method,
        p.provider,
        p.status,
        p.paid_at,

        i.id AS invoice_id,
        i.invoice_number,

        c.name AS customer_name

    FROM payments p

    INNER JOIN invoices i
        ON i.id = p.invoice_id

    INNER JOIN customers c
        ON c.id = i.customer_id

    WHERE i.user_id = ?

    ORDER BY p.id DESC

    LIMIT 5
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$recentPayments = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

foreach ($recentPayments as &$payment) {

    $payment['id'] =
        (int)$payment['id'];

    $payment['invoice_id'] =
        (int)$payment['invoice_id'];

    $payment['amount'] =
        (float)$payment['amount'];
}

unset($payment);

/*
|--------------------------------------------------------------------------
| Normalize financial values
|--------------------------------------------------------------------------
*/

$totalInvoiced = (float)(
    $invoiceSummary['total_invoiced']
    ?? 0
);

$totalReceived = (float)(
    $invoiceSummary['total_received']
    ?? 0
);

$outstandingBalance = (float)(
    $invoiceSummary['outstanding_balance']
    ?? 0
);

$totalExpenses = (float)(
    $expenseSummary['total_expenses']
    ?? 0
);

$monthlyReceived = (float)(
    $monthlyInvoices['received_amount']
    ?? 0
);

$monthlyExpenseAmount = (float)(
    $monthlyExpenses['amount']
    ?? 0
);

/*
|--------------------------------------------------------------------------
| Simple cash-position metrics
|--------------------------------------------------------------------------
|
| This is not full accounting profit.
| It is simply received invoice payments minus recorded expenses.
|--------------------------------------------------------------------------
*/

$cashPosition = round(
    $totalReceived - $totalExpenses,
    2
);

$monthlyCashPosition = round(
    $monthlyReceived - $monthlyExpenseAmount,
    2
);

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'overview' => [

        'customers' =>
            $customerCount,

        'invoices' => [
            'total' =>
                (int)$invoiceSummary['total_invoices'],

            'paid' =>
                (int)$invoiceSummary['paid_invoices'],

            'unpaid' =>
                (int)$invoiceSummary['unpaid_invoices'],

            'overdue' =>
                (int)$invoiceSummary['overdue_invoices']
        ],

        'financials' => [
            'total_invoiced' =>
                round($totalInvoiced, 2),

            'total_received' =>
                round($totalReceived, 2),

            'outstanding_balance' =>
                round($outstandingBalance, 2),

            'total_expenses' =>
                round($totalExpenses, 2),

            'cash_position' =>
                $cashPosition
        ],

        'payments' => [
            'successful_count' =>
                (int)$paymentSummary['total_payments'],

            'successful_volume' =>
                round(
                    (float)$paymentSummary['payment_volume'],
                    2
                )
        ],

        'recurring' => [
            'total' =>
                (int)$recurringSummary['total'],

            'active' =>
                (int)$recurringSummary['active'],

            'inactive' =>
                (int)$recurringSummary['inactive']
        ]
    ],

    'current_month' => [
        'period' => [
            'from' => $monthStart,
            'to' => $monthEnd
        ],

        'invoices_created' =>
            (int)$monthlyInvoices['invoices_created'],

        'invoiced_amount' =>
            round(
                (float)$monthlyInvoices['invoiced_amount'],
                2
            ),

        'received_amount' =>
            round($monthlyReceived, 2),

        'expenses' =>
            round($monthlyExpenseAmount, 2),

        'expense_tax' =>
            round(
                (float)$monthlyExpenses['tax'],
                2
            ),

        'cash_position' =>
            $monthlyCashPosition
    ],

    'recent' => [
        'invoices' =>
            $recentInvoices,

        'payments' =>
            $recentPayments
    ]
]);

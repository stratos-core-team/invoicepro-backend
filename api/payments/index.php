<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

$user = require_auth_user();
$db = db();

/*
|--------------------------------------------------------------------------
| GET - List payments
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $db->prepare("
        SELECT
            p.id,
            p.invoice_id,
            p.reference,
            p.provider,
            p.amount,
            p.method,
            p.status,
            p.provider_reference,
            p.paid_at,
            p.created_at,

            i.invoice_number,
            i.total AS invoice_total,

            c.name AS customer_name

        FROM payments p

        INNER JOIN invoices i
            ON i.id = p.invoice_id

        INNER JOIN customers c
            ON c.id = i.customer_id

        WHERE i.user_id = ?

        ORDER BY p.id DESC
    ");

    $stmt->bind_param(
        'i',
        $user['id']
    );

    $stmt->execute();

    $payments = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    api_success([
        'payments' => $payments
    ]);
}


/*
|--------------------------------------------------------------------------
| POST - Record payment
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = request_json();

    $invoiceId = (int)(
        $input['invoice_id'] ?? 0
    );

    $amount = (float)(
        $input['amount'] ?? 0
    );

    $method = trim(
        (string)($input['method'] ?? '')
    );

    $provider = trim(
        (string)($input['provider'] ?? 'manual')
    );

    $reference = trim(
        (string)($input['reference'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($invoiceId <= 0) {
        api_error(
            'A valid invoice_id is required.',
            422
        );
    }

    if ($amount <= 0) {
        api_error(
            'Payment amount must be greater than zero.',
            422
        );
    }

    if ($method === '') {
        api_error(
            'Payment method is required.',
            422
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Fetch invoice
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            id,
            invoice_number,
            total,
            amount_paid,
            status

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
        api_error(
            'Invoice not found.',
            404
        );
    }

    if ($invoice['status'] === 'cancelled') {
        api_error(
            'Payments cannot be recorded for a cancelled invoice.',
            409
        );
    }

    if ($invoice['status'] === 'paid') {
        api_error(
            'Invoice is already fully paid.',
            409
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Calculate balance
    |--------------------------------------------------------------------------
    */

    $invoiceTotal =
        (float)$invoice['total'];

    $alreadyPaid =
        (float)$invoice['amount_paid'];

    $balance = round(
        $invoiceTotal - $alreadyPaid,
        2
    );

    if ($amount > $balance) {
        api_error(
            'Payment amount cannot exceed invoice balance.',
            422,
            [
                'invoice_total' => $invoiceTotal,
                'amount_paid' => $alreadyPaid,
                'balance' => $balance
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Generate reference when missing
    |--------------------------------------------------------------------------
    */

    if ($reference === '') {

        $reference =
            'PAY-' .
            strtoupper(
                bin2hex(
                    random_bytes(5)
                )
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    $db->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Insert payment
        |--------------------------------------------------------------------------
        */

        $paymentStmt = $db->prepare("
            INSERT INTO payments
            (
                invoice_id,
                reference,
                provider,
                amount,
                method,
                status,
                paid_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                'successful',
                NOW()
            )
        ");

        $paymentStmt->bind_param(
            'issds',
            $invoiceId,
            $reference,
            $provider,
            $amount,
            $method
        );

        $paymentStmt->execute();

        $paymentId =
            (int)$db->insert_id;


        /*
        |--------------------------------------------------------------------------
        | Calculate new amount paid
        |--------------------------------------------------------------------------
        */

        $newAmountPaid = round(
            $alreadyPaid + $amount,
            2
        );

        $newStatus =
            $newAmountPaid >= $invoiceTotal
                ? 'paid'
                : 'unpaid';


        /*
        |--------------------------------------------------------------------------
        | Update invoice
        |--------------------------------------------------------------------------
        */

        if ($newStatus === 'paid') {

            $invoiceStmt = $db->prepare("
                UPDATE invoices

                SET
                    amount_paid = ?,
                    status = 'paid',
                    paid_at = NOW()

                WHERE id = ?
                  AND user_id = ?
            ");

        } else {

            $invoiceStmt = $db->prepare("
                UPDATE invoices

                SET
                    amount_paid = ?,
                    status = 'unpaid',
                    paid_at = NULL

                WHERE id = ?
                  AND user_id = ?
            ");
        }

        $invoiceStmt->bind_param(
            'dii',
            $newAmountPaid,
            $invoiceId,
            $user['id']
        );

        $invoiceStmt->execute();

        $db->commit();

    } catch (Throwable $e) {

        $db->rollback();

        api_error(
            'Could not record payment.',
            500
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    api_success([
        'payment' => [
            'id' => $paymentId,
            'invoice_id' => $invoiceId,
            'invoice_number' =>
                $invoice['invoice_number'],

            'reference' => $reference,
            'provider' => $provider,
            'method' => $method,
            'amount' => $amount
        ],

        'invoice' => [
            'total' => $invoiceTotal,

            'amount_paid' =>
                $newAmountPaid,

            'balance' => max(
                0,
                round(
                    $invoiceTotal -
                    $newAmountPaid,
                    2
                )
            ),

            'status' => $newStatus
        ]

    ], 'Payment recorded successfully.', 201);
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

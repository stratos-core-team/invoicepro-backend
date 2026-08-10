<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

$customerId = (int)($_GET['id'] ?? 0);

if ($customerId <= 0) {
    api_error('A valid customer id is required.', 422);
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
        email,
        phone,
        address
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
    api_error('Customer not found.', 404);
}

/*
|--------------------------------------------------------------------------
| Read JSON input
|--------------------------------------------------------------------------
*/

$input = request_json();

/*
|--------------------------------------------------------------------------
| Use existing values when fields are not supplied
|--------------------------------------------------------------------------
*/

$name = array_key_exists('name', $input)
    ? trim((string)$input['name'])
    : $customer['name'];

$email = array_key_exists('email', $input)
    ? strtolower(trim((string)$input['email']))
    : $customer['email'];

$phone = array_key_exists('phone', $input)
    ? trim((string)$input['phone'])
    : $customer['phone'];

$address = array_key_exists('address', $input)
    ? trim((string)$input['address'])
    : $customer['address'];

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

$errors = [];

if ($name === '') {
    $errors['name'] = 'Customer name is required.';
}

if (
    $email !== ''
    && $email !== null
    && !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    $errors['email'] = 'A valid email address is required.';
}

if (strlen((string)$name) > 160) {
    $errors['name'] = 'Customer name is too long.';
}

if (strlen((string)$email) > 190) {
    $errors['email'] = 'Email address is too long.';
}

if (strlen((string)$phone) > 40) {
    $errors['phone'] = 'Phone number is too long.';
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
| Update customer
|--------------------------------------------------------------------------
*/

try {

    $update = $db->prepare("
        UPDATE customers
        SET
            name = ?,
            email = ?,
            phone = ?,
            address = ?
        WHERE id = ?
          AND user_id = ?
    ");

    $update->bind_param(
        'ssssii',
        $name,
        $email,
        $phone,
        $address,
        $customerId,
        $user['id']
    );

    $update->execute();

} catch (Throwable $e) {

    api_error(
        'Could not update customer.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Fetch updated customer
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        address,
        created_at,
        updated_at
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

$updatedCustomer = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'customer' => $updatedCustomer
], 'Customer updated successfully.');
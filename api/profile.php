<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

$user = require_auth_user();
$db = db();

/*
|--------------------------------------------------------------------------
| GET BUSINESS PROFILE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $db->prepare("
        SELECT
            u.id AS user_id,
            u.full_name,
            u.business_name AS account_business_name,
            u.email AS account_email,
            u.plan,

            b.id AS profile_id,
            b.business_name,
            b.phone,
            b.email,
            b.address,
            b.logo,
            b.tax_number,
            b.created_at,
            b.updated_at

        FROM users u

        LEFT JOIN business_profiles b
            ON b.user_id = u.id

        WHERE u.id = ?

        LIMIT 1
    ");

    $stmt->bind_param(
        'i',
        $user['id']
    );

    $stmt->execute();

    $profile = $stmt
        ->get_result()
        ->fetch_assoc();

    if (!$profile) {
        api_error(
            'Business profile not found.',
            404
        );
    }

    api_success([
        'business' => [
            'user_id' => (int)$profile['user_id'],
            'full_name' => $profile['full_name'],

            'business_name' =>
                $profile['business_name']
                ?: $profile['account_business_name'],

            'email' =>
                $profile['email']
                ?: $profile['account_email'],

            'phone' => $profile['phone'],
            'address' => $profile['address'],
            'logo' => $profile['logo'],
            'tax_number' => $profile['tax_number'],
            'plan' => $profile['plan'],

            'created_at' => $profile['created_at'],
            'updated_at' => $profile['updated_at']
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| UPDATE BUSINESS PROFILE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'PUT'
    || $_SERVER['REQUEST_METHOD'] === 'PATCH'
) {

    $input = request_json();

    /*
    |--------------------------------------------------------------------------
    | Fetch current profile
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            business_name,
            phone,
            email,
            address,
            logo,
            tax_number
        FROM business_profiles
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'i',
        $user['id']
    );

    $stmt->execute();

    $current = $stmt
        ->get_result()
        ->fetch_assoc();

    /*
    |--------------------------------------------------------------------------
    | Create profile if missing
    |--------------------------------------------------------------------------
    */

    if (!$current) {

        $insert = $db->prepare("
            INSERT INTO business_profiles
            (
                user_id,
                business_name,
                email
            )
            VALUES (?, ?, ?)
        ");

        $insert->bind_param(
            'iss',
            $user['id'],
            $user['business_name'],
            $user['email']
        );

        $insert->execute();

        $current = [
            'business_name' => $user['business_name'],
            'phone' => '',
            'email' => $user['email'],
            'address' => '',
            'logo' => null,
            'tax_number' => ''
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Use existing values for missing fields
    |--------------------------------------------------------------------------
    */

    $businessName = array_key_exists(
        'business_name',
        $input
    )
        ? trim((string)$input['business_name'])
        : (string)$current['business_name'];

    $phone = array_key_exists(
        'phone',
        $input
    )
        ? trim((string)$input['phone'])
        : (string)($current['phone'] ?? '');

    $email = array_key_exists(
        'email',
        $input
    )
        ? strtolower(
            trim((string)$input['email'])
        )
        : (string)($current['email'] ?? '');

    $address = array_key_exists(
        'address',
        $input
    )
        ? trim((string)$input['address'])
        : (string)($current['address'] ?? '');

    $taxNumber = array_key_exists(
        'tax_number',
        $input
    )
        ? trim((string)$input['tax_number'])
        : (string)($current['tax_number'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    $errors = [];

    if ($businessName === '') {
        $errors['business_name'] =
            'Business name is required.';
    }

    if (
        $email !== ''
        && !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors['email'] =
            'A valid business email is required.';
    }

    if (strlen($businessName) > 160) {
        $errors['business_name'] =
            'Business name is too long.';
    }

    if (strlen($email) > 190) {
        $errors['email'] =
            'Email is too long.';
    }

    if (strlen($phone) > 40) {
        $errors['phone'] =
            'Phone number is too long.';
    }

    if (strlen($taxNumber) > 100) {
        $errors['tax_number'] =
            'Tax number is too long.';
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
    | Update transaction
    |--------------------------------------------------------------------------
    */

    $db->begin_transaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Update account business name
        |--------------------------------------------------------------------------
        */

        $userStmt = $db->prepare("
            UPDATE users
            SET business_name = ?
            WHERE id = ?
        ");

        $userStmt->bind_param(
            'si',
            $businessName,
            $user['id']
        );

        $userStmt->execute();

        /*
        |--------------------------------------------------------------------------
        | Update business profile
        |--------------------------------------------------------------------------
        */

        $profileStmt = $db->prepare("
            UPDATE business_profiles
            SET
                business_name = ?,
                phone = ?,
                email = ?,
                address = ?,
                tax_number = ?
            WHERE user_id = ?
        ");

        $profileStmt->bind_param(
            'sssssi',
            $businessName,
            $phone,
            $email,
            $address,
            $taxNumber,
            $user['id']
        );

        $profileStmt->execute();

        $db->commit();

    } catch (Throwable $e) {

        $db->rollback();

        api_error(
            'Could not update business profile.',
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Return updated profile
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            business_name,
            phone,
            email,
            address,
            logo,
            tax_number,
            updated_at
        FROM business_profiles
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'i',
        $user['id']
    );

    $stmt->execute();

    $updated = $stmt
        ->get_result()
        ->fetch_assoc();

    api_success([
        'business' => $updated
    ], 'Business profile updated successfully.');
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
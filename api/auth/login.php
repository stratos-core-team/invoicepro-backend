<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/response.php';
require_once __DIR__ . '/../../core/request.php';
require_once __DIR__ . '/../../core/jwt.php';

/*
|--------------------------------------------------------------------------
| Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(
        'Method not allowed.',
        405
    );
}

$db = db();
$input = request_json();

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$email = strtolower(
    trim(
        (string)($input['email'] ?? '')
    )
);

$password =
    (string)($input['password'] ?? '');

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

$errors = [];

if ($email === '') {

    $errors['email'] =
        'Email is required.';

} elseif (
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {

    $errors['email'] =
        'Please provide a valid email address.';
}

if ($password === '') {
    $errors['password'] =
        'Password is required.';
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
| Find User
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        full_name,
        business_name,
        email,
        email_verified_at,
        password_hash,
        plan,
        status

    FROM users

    WHERE email = ?

    LIMIT 1
");

$stmt->bind_param(
    's',
    $email
);

$stmt->execute();

$user = $stmt
    ->get_result()
    ->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Verify Credentials
|--------------------------------------------------------------------------
|
| Keep the same response whether email or password is wrong.
|--------------------------------------------------------------------------
*/

if (
    !$user
    || !password_verify(
        $password,
        $user['password_hash']
    )
) {

    api_error(
        'Invalid email or password.',
        401
    );
}

/*
|--------------------------------------------------------------------------
| Account Status
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'active') {

    api_error(
        'Account inactive.',
        403,
        [
            'code' => 'ACCOUNT_INACTIVE'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Email Verification
|--------------------------------------------------------------------------
*/

if (empty($user['email_verified_at'])) {

    api_error(
        'Please verify your email address before logging in.',
        403,
        [
            'code' => 'EMAIL_NOT_VERIFIED',
            'email' => $user['email']
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Subscription Expiry Safety Check
|--------------------------------------------------------------------------
|
| Cron handles normal expiry, but login also checks the current user's
| subscription so an expired Pro account does not keep Pro access merely
| because cron has not run yet.
|--------------------------------------------------------------------------
*/

if ($user['plan'] === 'pro') {

    $subscriptionStmt = $db->prepare("
        SELECT
            id,
            status,
            expires_at

        FROM subscriptions

        WHERE user_id = ?
          AND plan = 'pro'
          AND status = 'active'

        ORDER BY id DESC

        LIMIT 1
    ");

    $subscriptionStmt->bind_param(
        'i',
        $user['id']
    );

    $subscriptionStmt->execute();

    $subscription = $subscriptionStmt
        ->get_result()
        ->fetch_assoc();

    $validPro = false;

    if ($subscription) {

        if (
            empty($subscription['expires_at'])
            || strtotime(
                $subscription['expires_at']
            ) > time()
        ) {
            $validPro = true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Downgrade stale Pro flag
    |--------------------------------------------------------------------------
    */

    if (!$validPro) {

        $downgrade = $db->prepare("
            UPDATE users
            SET plan = 'free'
            WHERE id = ?
        ");

        $downgrade->bind_param(
            'i',
            $user['id']
        );

        $downgrade->execute();

        $user['plan'] = 'free';
    }
}

/*
|--------------------------------------------------------------------------
| JWT
|--------------------------------------------------------------------------
*/

$token = jwt_encode([
    'sub' =>
        (int)$user['id'],

    'email' =>
        $user['email']
   'ver' => (int)$user['token_version']                 
]);

/*
|--------------------------------------------------------------------------
| Remove sensitive information
|--------------------------------------------------------------------------
*/

unset(
    $user['password_hash']
);

/*
|--------------------------------------------------------------------------
| Normalize
|--------------------------------------------------------------------------
*/

$user['id'] =
    (int)$user['id'];

$user['email_verified'] =
    !empty(
        $user['email_verified_at']
    );

unset(
    $user['email_verified_at']
);

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success(
    [
        'token' =>
            $token,

        'user' =>
            $user
    ],
    'Login successful.'
);

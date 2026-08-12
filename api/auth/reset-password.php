<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/response.php';
require_once __DIR__ . '/../../core/request.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$db = db();
$input = request_json();

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$token = trim(
    (string)($input['token'] ?? '')
);

$password =
    (string)($input['password'] ?? '');

$passwordConfirmation =
    (string)(
        $input['password_confirmation']
        ?? ''
    );

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

$errors = [];

if ($token === '') {

    $errors['token'] =
        'Password reset token is required.';
}

if ($password === '') {

    $errors['password'] =
        'Password is required.';

} elseif (strlen($password) < 8) {

    $errors['password'] =
        'Password must contain at least 8 characters.';
}

if ($passwordConfirmation === '') {

    $errors['password_confirmation'] =
        'Password confirmation is required.';

} elseif ($password !== $passwordConfirmation) {

    $errors['password_confirmation'] =
        'Password confirmation does not match.';
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
| Hash supplied token
|--------------------------------------------------------------------------
*/

$tokenHash =
    hash(
        'sha256',
        $token
    );

/*
|--------------------------------------------------------------------------
| Find reset request
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        email,
        status,
        password_reset_expires_at

    FROM users

    WHERE password_reset_token = ?

    LIMIT 1
");

$stmt->bind_param(
    's',
    $tokenHash
);

$stmt->execute();

$user = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$user) {

    api_error(
        'Invalid or expired password reset link.',
        400,
        [
            'code' => 'INVALID_RESET_TOKEN'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Account status
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'active') {

    api_error(
        'Password cannot be reset for this account.',
        403,
        [
            'code' => 'ACCOUNT_INACTIVE'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Check token expiry
|--------------------------------------------------------------------------
*/

if (
    empty($user['password_reset_expires_at'])
    || strtotime(
        $user['password_reset_expires_at']
    ) < time()
) {

    /*
    |--------------------------------------------------------------------------
    | Remove expired token
    |--------------------------------------------------------------------------
    */

    $clear = $db->prepare("
        UPDATE users

        SET
            password_reset_token = NULL,
            password_reset_expires_at = NULL

        WHERE id = ?
    ");

    $clear->bind_param(
        'i',
        $user['id']
    );

    $clear->execute();

    api_error(
        'Password reset link has expired.',
        410,
        [
            'code' => 'RESET_TOKEN_EXPIRED'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Create password hash
|--------------------------------------------------------------------------
*/

$passwordHash =
    password_hash(
        $password,
        PASSWORD_DEFAULT
    );

if ($passwordHash === false) {

    api_error(
        'Could not securely process password.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Update password
|--------------------------------------------------------------------------
|
| Token is cleared immediately, making it single-use.
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    $update = $db->prepare("
        UPDATE users

        SET
            password_hash = ?,
            password_reset_token = NULL,
            password_reset_expires_at = NULL

        WHERE id = ?
          AND password_reset_token = ?
    ");

    $update->bind_param(
        'sis',
        $passwordHash,
        $user['id'],
        $tokenHash
    );

    $update->execute();

    if ($update->affected_rows !== 1) {

        throw new RuntimeException(
            'Reset token could not be consumed.'
        );
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not reset password.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success(
    [
        'password_reset' => true
    ],
    'Password reset successfully. You can now log in with your new password.'
);

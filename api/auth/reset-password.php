<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/response.php';
require_once __DIR__ . '/../../core/request.php';

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

$token = trim(
    (string)($input['token'] ?? '')
);

$password =
    (string)($input['password'] ?? '');

$passwordConfirmation =
    (string)(
        $input['password_confirmation'] ?? ''
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
| Hash supplied reset token
|--------------------------------------------------------------------------
|
| Token halisi haijahifadhiwa database.
| Database ina SHA-256 hash yake.
|--------------------------------------------------------------------------
*/

$tokenHash =
    hash(
        'sha256',
        $token
    );

/*
|--------------------------------------------------------------------------
| Find user by reset token
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

/*
|--------------------------------------------------------------------------
| Invalid Token
|--------------------------------------------------------------------------
*/

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
| Account Status
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
| Check Token Expiration
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
    | Delete expired reset token
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
| Generate New Password Hash
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
| Update Password
|--------------------------------------------------------------------------
|
| Hapa tunafanya mambo manne:
|
| 1. Tunabadilisha password.
| 2. Tunaongeza token_version.
| 3. Tunafuta reset token.
| 4. Tunafuta expiry ya reset token.
|
| Kuongeza token_version kunafanya JWT zote za zamani kuwa invalid.
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    $update = $db->prepare("
        UPDATE users

        SET
            password_hash = ?,
            token_version = token_version + 1,
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

    /*
    |--------------------------------------------------------------------------
    | Make sure token was consumed successfully
    |--------------------------------------------------------------------------
    */

    if ($update->affected_rows !== 1) {

        throw new RuntimeException(
            'Reset token could not be consumed.'
        );
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    error_log(
        'Password reset failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not reset password.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/

api_success(
    [
        'password_reset' => true,
        'sessions_revoked' => true
    ],
    'Password reset successfully. Please log in with your new password.'
);

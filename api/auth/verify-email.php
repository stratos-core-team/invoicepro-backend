<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$db = db();
$input = request_json();

$token = trim(
    (string)($input['token'] ?? '')
);

if ($token === '') {
    api_error(
        'Verification token is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Hash incoming token
|--------------------------------------------------------------------------
|
| Database should store only the token hash.
|--------------------------------------------------------------------------
*/

$tokenHash = hash(
    'sha256',
    $token
);

/*
|--------------------------------------------------------------------------
| Find user
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        email,
        email_verified_at,
        email_verification_expires_at

    FROM users

    WHERE email_verification_token = ?

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
        'Invalid verification token.',
        400
    );
}

/*
|--------------------------------------------------------------------------
| Already verified
|--------------------------------------------------------------------------
*/

if (!empty($user['email_verified_at'])) {

    api_success([
        'verified' => true
    ], 'Email is already verified.');
}

/*
|--------------------------------------------------------------------------
| Check expiry
|--------------------------------------------------------------------------
*/

if (
    empty($user['email_verification_expires_at'])
    || strtotime(
        $user['email_verification_expires_at']
    ) < time()
) {

    api_error(
        'Verification link has expired.',
        410
    );
}

/*
|--------------------------------------------------------------------------
| Verify email
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    UPDATE users

    SET
        email_verified_at = NOW(),
        email_verification_token = NULL,
        email_verification_expires_at = NULL

    WHERE id = ?
");

$stmt->bind_param(
    'i',
    $user['id']
);

$stmt->execute();

api_success([
    'verified' => true,
    'email' => $user['email']
], 'Email verified successfully.');

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/response.php';
require_once __DIR__ . '/../../core/request.php';
require_once __DIR__ . '/../../services/MailService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$db = db();
$input = request_json();

$email = strtolower(
    trim((string)($input['email'] ?? ''))
);

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

if (
    $email === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    api_error(
        'A valid email address is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Generic response
|--------------------------------------------------------------------------
|
| Hii inasaidia kuzuia mtu kutumia endpoint kujua emails zilizosajiliwa.
|--------------------------------------------------------------------------
*/

$genericMessage =
    'If an account exists for this email, a password reset link will be sent.';

/*
|--------------------------------------------------------------------------
| Find user
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        full_name,
        email,
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

if (!$user) {

    api_success(
        ['request_received' => true],
        $genericMessage
    );
}

/*
|--------------------------------------------------------------------------
| Do not expose account status
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'active') {

    api_success(
        ['request_received' => true],
        $genericMessage
    );
}

/*
|--------------------------------------------------------------------------
| Generate reset token
|--------------------------------------------------------------------------
*/

try {

    $resetToken =
        bin2hex(
            random_bytes(32)
        );

} catch (Throwable $e) {

    api_error(
        'Could not process password reset request.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Store only token hash
|--------------------------------------------------------------------------
*/

$resetTokenHash =
    hash(
        'sha256',
        $resetToken
    );

/*
|--------------------------------------------------------------------------
| Token expires after 1 hour
|--------------------------------------------------------------------------
*/

$expiresAt =
    date(
        'Y-m-d H:i:s',
        time() + 3600
    );

/*
|--------------------------------------------------------------------------
| Save reset request
|--------------------------------------------------------------------------
*/

try {

    $update = $db->prepare("
        UPDATE users

        SET
            password_reset_token = ?,
            password_reset_expires_at = ?

        WHERE id = ?
    ");

    $update->bind_param(
        'ssi',
        $resetTokenHash,
        $expiresAt,
        $user['id']
    );

    $update->execute();

} catch (Throwable $e) {

    api_error(
        'Could not process password reset request.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Send email
|--------------------------------------------------------------------------
*/

try {

    $mailer =
        new MailService();

    $mailer->sendPasswordResetEmail(
        $user['email'],
        $user['full_name'],
        $resetToken
    );

} catch (Throwable $e) {

    error_log(
        'Password reset email failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    /*
    |--------------------------------------------------------------------------
    | Do not expose mail delivery details
    |--------------------------------------------------------------------------
    */

    api_success(
        ['request_received' => true],
        $genericMessage
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success(
    [
        'request_received' => true
    ],
    $genericMessage
);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/response.php';
require_once __DIR__ . '/../../core/request.php';
require_once __DIR__ . '/../../services/MailService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(
        'Method not allowed.',
        405
    );
}

$db = db();
$input = request_json();

$email = strtolower(
    trim(
        (string)($input['email'] ?? '')
    )
);

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

if (
    $email === ''
    || !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {
    api_error(
        'A valid email address is required.',
        422
    );
}

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
        email_verified_at,
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
| Prevent account enumeration
|--------------------------------------------------------------------------
|
| We intentionally return a generic response when email does not exist.
|--------------------------------------------------------------------------
*/

if (!$user) {

    api_success(
        [
            'email_sent' => true
        ],
        'If an account exists for this email, a verification link will be sent.'
    );
}

/*
|--------------------------------------------------------------------------
| Account state
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'active') {

    api_error(
        'Account is not active.',
        403,
        [
            'code' => 'ACCOUNT_INACTIVE'
        ]
    );
}

if (!empty($user['email_verified_at'])) {

    api_success(
        [
            'verified' => true,
            'email_sent' => false
        ],
        'Email is already verified.'
    );
}

/*
|--------------------------------------------------------------------------
| Generate new secure verification token
|--------------------------------------------------------------------------
*/

try {

    $verificationToken =
        bin2hex(
            random_bytes(32)
        );

} catch (Throwable $e) {

    api_error(
        'Could not generate verification token.',
        500
    );
}

$verificationTokenHash =
    hash(
        'sha256',
        $verificationToken
    );

$expiresAt =
    date(
        'Y-m-d H:i:s',
        time() + 86400
    );

/*
|--------------------------------------------------------------------------
| Save token hash
|--------------------------------------------------------------------------
*/

try {

    $update = $db->prepare("
        UPDATE users

        SET
            email_verification_token = ?,
            email_verification_expires_at = ?

        WHERE id = ?
          AND email_verified_at IS NULL
    ");

    $update->bind_param(
        'ssi',
        $verificationTokenHash,
        $expiresAt,
        $user['id']
    );

    $update->execute();

} catch (Throwable $e) {

    api_error(
        'Could not create a new verification request.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Send verification email
|--------------------------------------------------------------------------
*/

$emailSent = false;

try {

    $mailer =
        new MailService();

    $emailSent =
        $mailer->sendVerificationEmail(
            $user['email'],
            $user['full_name'],
            $verificationToken
        );

} catch (Throwable $e) {

    error_log(
        'Resend verification email failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

if (!$emailSent) {

    api_error(
        'Verification email could not be sent at this time.',
        503
    );
}

api_success(
    [
        'email_sent' => true,
        'expires_in' => 86400
    ],
    'A new verification email has been sent.'
);

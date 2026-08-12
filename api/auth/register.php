<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/response.php';
require_once __DIR__ . '/../../core/request.php';
require_once __DIR__ . '/../../services/MailService.php';

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

$fullName = trim(
    (string)($input['full_name'] ?? '')
);

$businessName = trim(
    (string)($input['business_name'] ?? '')
);

$email = strtolower(
    trim(
        (string)($input['email'] ?? '')
    )
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

if ($fullName === '') {

    $errors['full_name'] =
        'Full name is required.';

} elseif (
    mb_strlen($fullName) > 120
) {

    $errors['full_name'] =
        'Full name must not exceed 120 characters.';
}

if ($businessName === '') {

    $errors['business_name'] =
        'Business name is required.';

} elseif (
    mb_strlen($businessName) > 160
) {

    $errors['business_name'] =
        'Business name must not exceed 160 characters.';
}

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

} elseif (
    strlen($password) < 8
) {

    $errors['password'] =
        'Password must contain at least 8 characters.';
}

if (
    $passwordConfirmation !== ''
    && $password !== $passwordConfirmation
) {

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
| Existing Email
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        email_verified_at

    FROM users

    WHERE email = ?

    LIMIT 1
");

$stmt->bind_param(
    's',
    $email
);

$stmt->execute();

$existingUser =
    $stmt
        ->get_result()
        ->fetch_assoc();

if ($existingUser) {

    api_error(
        'An account with this email already exists.',
        409
    );
}

/*
|--------------------------------------------------------------------------
| Password Hash
|--------------------------------------------------------------------------
*/

$passwordHash = password_hash(
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
| Email Verification Token
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

$verificationExpiresAt =
    date(
        'Y-m-d H:i:s',
        time() + 86400
    );

/*
|--------------------------------------------------------------------------
| Defaults
|--------------------------------------------------------------------------
*/

$defaultPlan =
    'free';

$defaultStatus =
    'active';

/*
|--------------------------------------------------------------------------
| Transaction
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Create user
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        INSERT INTO users
        (
            full_name,
            business_name,
            email,
            email_verified_at,
            email_verification_token,
            email_verification_expires_at,
            password_hash,
            plan,
            status
        )

        VALUES
        (
            ?,
            ?,
            ?,
            NULL,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    $stmt->bind_param(
        'ssssssss',
        $fullName,
        $businessName,
        $email,
        $verificationTokenHash,
        $verificationExpiresAt,
        $passwordHash,
        $defaultPlan,
        $defaultStatus
    );

    $stmt->execute();

    $userId =
        (int)$db->insert_id;

    /*
    |--------------------------------------------------------------------------
    | Create business profile
    |--------------------------------------------------------------------------
    */

    $businessStmt = $db->prepare("
        INSERT INTO business_profiles
        (
            user_id,
            business_name,
            email
        )

        VALUES (?, ?, ?)
    ");

    $businessStmt->bind_param(
        'iss',
        $userId,
        $businessName,
        $email
    );

    $businessStmt->execute();

    /*
    |--------------------------------------------------------------------------
    | Create Free subscription
    |--------------------------------------------------------------------------
    */

    $subscriptionStmt = $db->prepare("
        INSERT INTO subscriptions
        (
            user_id,
            plan,
            billing_cycle,
            status,
            starts_at,
            expires_at
        )

        VALUES
        (
            ?,
            'free',
            NULL,
            'active',
            NOW(),
            NULL
        )
    ");

    $subscriptionStmt->bind_param(
        'i',
        $userId
    );

    $subscriptionStmt->execute();

    $db->commit();

} catch (mysqli_sql_exception $e) {

    $db->rollback();

    if ((int)$e->getCode() === 1062) {

        api_error(
            'An account with this email already exists.',
            409
        );
    }

    api_error(
        'Could not create account.',
        500
    );

} catch (Throwable $e) {

    $db->rollback();

    api_error(
        'Could not create account.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Send Verification Email
|--------------------------------------------------------------------------
*/

$emailSent = false;

try {

    $mailer =
        new MailService();

    $emailSent =
        $mailer->sendVerificationEmail(
            $email,
            $fullName,
            $verificationToken
        );

} catch (Throwable $e) {

    $emailSent = false;

    error_log(
        'Verification email failed for user '
        . $userId
        . ': '
        . $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success(
    [
        'user' => [
            'id' =>
                $userId,

            'full_name' =>
                $fullName,

            'business_name' =>
                $businessName,

            'email' =>
                $email,

            'plan' =>
                $defaultPlan,

            'status' =>
                $defaultStatus,

            'email_verified' =>
                false
        ],

        'verification' => [
            'required' =>
                true,

            'email_sent' =>
                $emailSent,

            'expires_in' =>
                86400
        ]
    ],

    $emailSent
        ? 'Account created successfully. Please check your email to verify your account.'
        : 'Account created successfully, but the verification email could not be sent. Please request a new verification email.',

    201
);

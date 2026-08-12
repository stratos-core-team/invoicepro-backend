<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../middleware/cors.php';
require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../core/request.php';
require_once __DIR__ . '/../../../services/TwoFactorService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(
        'Method not allowed.',
        405
    );
}

$user = require_auth_user();
$db = db();
$input = request_json();

$password =
    (string)($input['password'] ?? '');

$code = trim(
    (string)($input['code'] ?? '')
);

if ($password === '') {
    api_error(
        'Password is required.',
        422
    );
}

$code = preg_replace(
    '/\D/',
    '',
    $code
) ?? '';

if (strlen($code) !== 6) {

    api_error(
        'A valid 6-digit authentication code is required.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Fetch account
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        password_hash,
        two_factor_enabled,
        two_factor_secret

    FROM users

    WHERE id = ?

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

if (!$current) {
    api_error(
        'User account not found.',
        404
    );
}

/*
|--------------------------------------------------------------------------
| Verify password
|--------------------------------------------------------------------------
*/

if (
    !password_verify(
        $password,
        $current['password_hash']
    )
) {

    api_error(
        'Invalid password.',
        401
    );
}

/*
|--------------------------------------------------------------------------
| 2FA must be enabled
|--------------------------------------------------------------------------
*/

if ((int)$current['two_factor_enabled'] !== 1) {

    api_error(
        'Two-factor authentication is not enabled.',
        409,
        [
            'code' =>
                'TWO_FACTOR_NOT_ENABLED'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Verify current TOTP
|--------------------------------------------------------------------------
*/

try {

    $twoFactor =
        new TwoFactorService();

    $secret =
        $twoFactor->decryptSecret(
            $current['two_factor_secret']
        );

    $valid =
        $twoFactor->verifyCode(
            $secret,
            $code
        );

} catch (Throwable $e) {

    error_log(
        'Recovery code regeneration verification failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not verify authentication code.',
        500
    );
}

if (!$valid) {

    api_error(
        'Invalid authentication code.',
        401,
        [
            'code' =>
                'INVALID_2FA_CODE'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Generate fresh recovery codes
|--------------------------------------------------------------------------
*/

try {

    $recoveryCodes =
        $twoFactor
            ->generateRecoveryCodes(8);

} catch (Throwable $e) {

    api_error(
        'Could not generate recovery codes.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Replace old codes
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Delete ALL old recovery codes
    |--------------------------------------------------------------------------
    */

    $delete = $db->prepare("
        DELETE FROM two_factor_recovery_codes
        WHERE user_id = ?
    ");

    $delete->bind_param(
        'i',
        $user['id']
    );

    $delete->execute();

    /*
    |--------------------------------------------------------------------------
    | Store hashes of new codes
    |--------------------------------------------------------------------------
    */

    $insert = $db->prepare("
        INSERT INTO two_factor_recovery_codes
        (
            user_id,
            code_hash
        )

        VALUES (?, ?)
    ");

    foreach ($recoveryCodes as $recoveryCode) {

        $hash =
            $twoFactor->hashRecoveryCode(
                $recoveryCode
            );

        $insert->bind_param(
            'is',
            $user['id'],
            $hash
        );

        $insert->execute();
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    error_log(
        'Recovery code regeneration failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not regenerate recovery codes.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
|
| These plain recovery codes are returned once.
|--------------------------------------------------------------------------
*/

api_success(
    [
        'recovery_codes' =>
            $recoveryCodes,

        'count' =>
            count($recoveryCodes)
    ],
    'New recovery codes generated successfully.'
);

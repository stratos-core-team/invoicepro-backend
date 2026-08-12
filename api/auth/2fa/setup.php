<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../middleware/cors.php';
require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../services/TwoFactorService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(
        'Method not allowed.',
        405
    );
}

$user = require_auth_user();
$db = db();

/*
|--------------------------------------------------------------------------
| Fetch current 2FA state
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        email,
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
| Prevent setup when already enabled
|--------------------------------------------------------------------------
*/

if ((int)$current['two_factor_enabled'] === 1) {

    api_error(
        'Two-factor authentication is already enabled.',
        409,
        [
            'code' => 'TWO_FACTOR_ALREADY_ENABLED'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Generate new secret
|--------------------------------------------------------------------------
*/

try {

    $twoFactor =
        new TwoFactorService();

    $secret =
        $twoFactor->generateSecret();

    $encryptedSecret =
        $twoFactor->encryptSecret(
            $secret
        );

    $otpAuthUri =
        $twoFactor->getOtpAuthUri(
            $secret,
            $current['email'],
            'InvoicePro NG'
        );

} catch (Throwable $e) {

    error_log(
        '2FA setup generation failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not initialize two-factor authentication.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Store encrypted pending secret
|--------------------------------------------------------------------------
|
| two_factor_enabled remains 0 until user confirms a valid TOTP code
| through enable.php.
|--------------------------------------------------------------------------
*/

try {

    $update = $db->prepare("
        UPDATE users

        SET
            two_factor_secret = ?,
            two_factor_enabled = 0

        WHERE id = ?
    ");

    $update->bind_param(
        'si',
        $encryptedSecret,
        $user['id']
    );

    $update->execute();

} catch (Throwable $e) {

    api_error(
        'Could not save two-factor setup.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Remove old unused recovery codes
|--------------------------------------------------------------------------
|
| A fresh setup should not retain recovery codes from a previous setup.
|--------------------------------------------------------------------------
*/

try {

    $deleteCodes = $db->prepare("
        DELETE FROM two_factor_recovery_codes
        WHERE user_id = ?
    ");

    $deleteCodes->bind_param(
        'i',
        $user['id']
    );

    $deleteCodes->execute();

} catch (Throwable $e) {

    error_log(
        'Could not clear old recovery codes for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
|
| Secret is returned only during setup so the frontend can display
| manual setup information and generate a QR code.
|--------------------------------------------------------------------------
*/

api_success(
    [
        'two_factor' => [
            'enabled' => false,

            'secret' =>
                $secret,

            'otpauth_uri' =>
                $otpAuthUri,

            'digits' =>
                6,

            'period' =>
                30,

            'algorithm' =>
                'SHA1'
        ]
    ],
    'Two-factor authentication setup initialized.'
);

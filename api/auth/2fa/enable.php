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
$input = request_json();

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$code = trim(
    (string)($input['code'] ?? '')
);

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

$code = preg_replace(
    '/\D/',
    '',
    $code
) ?? '';

if (strlen($code) !== 6) {

    api_error(
        'A valid 6-digit authentication code is required.',
        422,
        [
            'code' => 'INVALID_TOTP_FORMAT'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Get User 2FA Setup
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
| Already Enabled
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
| Setup Required
|--------------------------------------------------------------------------
*/

if (empty($current['two_factor_secret'])) {

    api_error(
        'Two-factor authentication setup has not been initialized.',
        409,
        [
            'code' => 'TWO_FACTOR_SETUP_REQUIRED'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Decrypt TOTP Secret
|--------------------------------------------------------------------------
*/

try {

    $twoFactor =
        new TwoFactorService();

    $secret =
        $twoFactor->decryptSecret(
            $current['two_factor_secret']
        );

} catch (Throwable $e) {

    error_log(
        '2FA secret decryption failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not verify two-factor authentication setup.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Verify Authenticator Code
|--------------------------------------------------------------------------
*/

try {

    $validCode =
        $twoFactor->verifyCode(
            $secret,
            $code
        );

} catch (Throwable $e) {

    error_log(
        '2FA verification failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not verify authentication code.',
        500
    );
}

if (!$validCode) {

    api_error(
        'Invalid authentication code.',
        422,
        [
            'code' => 'INVALID_TOTP_CODE'
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Generate Recovery Codes
|--------------------------------------------------------------------------
|
| Plain recovery codes will be returned ONLY this time.
| Database stores only SHA-256 hashes.
|--------------------------------------------------------------------------
*/

try {

    $recoveryCodes =
        $twoFactor->generateRecoveryCodes(8);

} catch (Throwable $e) {

    api_error(
        'Could not generate recovery codes.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Enable 2FA
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Remove previous recovery codes
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
    | Save Recovery Code Hashes
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

        $codeHash =
            $twoFactor->hashRecoveryCode(
                $recoveryCode
            );

        $insert->bind_param(
            'is',
            $user['id'],
            $codeHash
        );

        $insert->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Enable 2FA + Revoke Existing JWTs
    |--------------------------------------------------------------------------
    |
    | token_version is incremented because existing sessions were created
    | before 2FA was enabled.
    |--------------------------------------------------------------------------
    */

    $enable = $db->prepare("
        UPDATE users

        SET
            two_factor_enabled = 1,
            token_version = token_version + 1

        WHERE id = ?
          AND two_factor_enabled = 0
    ");

    $enable->bind_param(
        'i',
        $user['id']
    );

    $enable->execute();

    if ($enable->affected_rows !== 1) {

        throw new RuntimeException(
            'Two-factor authentication could not be enabled.'
        );
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    error_log(
        '2FA enable failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not enable two-factor authentication.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
|
| Recovery codes are intentionally returned once.
| Frontend should clearly ask the user to save them securely.
|--------------------------------------------------------------------------
*/

api_success(
    [
        'two_factor' => [
            'enabled' => true
        ],

        'recovery_codes' =>
            $recoveryCodes,

        'sessions_revoked' =>
            true
    ],
    'Two-factor authentication enabled successfully.'
);

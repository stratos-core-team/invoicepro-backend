<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../middleware/cors.php';
require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../core/request.php';
require_once __DIR__ . '/../../../services/TwoFactorService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();
$input = request_json();

$password = (string)($input['password'] ?? '');
$code = trim((string)($input['code'] ?? ''));
$recoveryCode = trim(
    (string)($input['recovery_code'] ?? '')
);

if ($password === '') {
    api_error(
        'Password is required.',
        422
    );
}

if ($code === '' && $recoveryCode === '') {
    api_error(
        'Authentication code or recovery code is required.',
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

$twoFactor = new TwoFactorService();
$verified = false;
$recoveryId = null;

/*
|--------------------------------------------------------------------------
| Verify TOTP
|--------------------------------------------------------------------------
*/

if ($code !== '') {

    try {

        $secret =
            $twoFactor->decryptSecret(
                $current['two_factor_secret']
            );

        $verified =
            $twoFactor->verifyCode(
                $secret,
                $code
            );

    } catch (Throwable $e) {

        error_log(
            '2FA disable verification failed for user '
            . $user['id']
            . ': '
            . $e->getMessage()
        );

        api_error(
            'Could not verify authentication code.',
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Recovery code fallback
|--------------------------------------------------------------------------
*/

if (
    !$verified
    && $recoveryCode !== ''
) {

    $hash =
        $twoFactor->hashRecoveryCode(
            $recoveryCode
        );

    $stmt = $db->prepare("
        SELECT id

        FROM two_factor_recovery_codes

        WHERE user_id = ?
          AND code_hash = ?
          AND used_at IS NULL

        LIMIT 1
    ");

    $stmt->bind_param(
        'is',
        $user['id'],
        $hash
    );

    $stmt->execute();

    $recovery = $stmt
        ->get_result()
        ->fetch_assoc();

    if ($recovery) {

        $verified = true;
        $recoveryId =
            (int)$recovery['id'];
    }
}

if (!$verified) {

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
| Disable 2FA
|--------------------------------------------------------------------------
*/

$db->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Mark recovery code used if applicable
    |--------------------------------------------------------------------------
    */

    if ($recoveryId !== null) {

        $consume = $db->prepare("
            UPDATE two_factor_recovery_codes

            SET used_at = NOW()

            WHERE id = ?
              AND user_id = ?
              AND used_at IS NULL
        ");

        $consume->bind_param(
            'ii',
            $recoveryId,
            $user['id']
        );

        $consume->execute();

        if ($consume->affected_rows !== 1) {
            throw new RuntimeException(
                'Recovery code could not be consumed.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete all recovery codes
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
    | Disable 2FA and revoke existing sessions
    |--------------------------------------------------------------------------
    */

    $disable = $db->prepare("
        UPDATE users

        SET
            two_factor_enabled = 0,
            two_factor_secret = NULL,
            token_version = token_version + 1

        WHERE id = ?
          AND two_factor_enabled = 1
    ");

    $disable->bind_param(
        'i',
        $user['id']
    );

    $disable->execute();

    if ($disable->affected_rows !== 1) {
        throw new RuntimeException(
            'Two-factor authentication could not be disabled.'
        );
    }

    $db->commit();

} catch (Throwable $e) {

    $db->rollback();

    error_log(
        '2FA disable failed for user '
        . $user['id']
        . ': '
        . $e->getMessage()
    );

    api_error(
        'Could not disable two-factor authentication.',
        500
    );
}

api_success(
    [
        'two_factor' => [
            'enabled' => false
        ],

        'sessions_revoked' => true
    ],
    'Two-factor authentication disabled successfully.'
);

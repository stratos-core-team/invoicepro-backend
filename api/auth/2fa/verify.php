<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../middleware/cors.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../core/response.php';
require_once __DIR__ . '/../../../core/request.php';
require_once __DIR__ . '/../../../core/jwt.php';
require_once __DIR__ . '/../../../services/TwoFactorService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$db = db();
$input = request_json();

$challengeToken = trim(
    (string)($input['challenge_token'] ?? '')
);

$code = trim(
    (string)($input['code'] ?? '')
);

$recoveryCode = trim(
    (string)($input['recovery_code'] ?? '')
);

if ($challengeToken === '') {
    api_error(
        '2FA challenge token is required.',
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
| Decode challenge token
|--------------------------------------------------------------------------
*/

$payload = jwt_decode(
    $challengeToken
);

if (
    !$payload ||
    empty($payload['sub']) ||
    ($payload['purpose'] ?? '') !== '2fa_challenge'
) {
    api_error(
        'Invalid or expired 2FA challenge.',
        401,
        ['code' => 'INVALID_2FA_CHALLENGE']
    );
}

$userId = (int)$payload['sub'];
$tokenVersion =
    (int)($payload['ver'] ?? 0);

/*
|--------------------------------------------------------------------------
| Fetch user
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT
        id,
        full_name,
        business_name,
        email,
        plan,
        status,
        token_version,
        two_factor_enabled,
        two_factor_secret

    FROM users

    WHERE id = ?

    LIMIT 1
");

$stmt->bind_param(
    'i',
    $userId
);

$stmt->execute();

$user = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$user) {
    api_error(
        'User account not found.',
        401
    );
}

if ($user['status'] !== 'active') {
    api_error(
        'Account inactive.',
        403
    );
}

if ((int)$user['two_factor_enabled'] !== 1) {
    api_error(
        'Two-factor authentication is not enabled.',
        409
    );
}

if (
    $tokenVersion !==
    (int)$user['token_version']
) {
    api_error(
        '2FA challenge is no longer valid.',
        401,
        ['code' => 'TOKEN_REVOKED']
    );
}

$twoFactor =
    new TwoFactorService();

$verified = false;
$usedRecoveryCodeId = null;

/*
|--------------------------------------------------------------------------
| Verify TOTP code
|--------------------------------------------------------------------------
*/

if ($code !== '') {

    try {

        $secret =
            $twoFactor->decryptSecret(
                $user['two_factor_secret']
            );

        $verified =
            $twoFactor->verifyCode(
                $secret,
                $code
            );

    } catch (Throwable $e) {

        error_log(
            '2FA verification error for user '
            . $userId
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
| Verify recovery code
|--------------------------------------------------------------------------
*/

if (
    !$verified &&
    $recoveryCode !== ''
) {

    $codeHash =
        $twoFactor->hashRecoveryCode(
            $recoveryCode
        );

    $recoveryStmt = $db->prepare("
        SELECT id
        FROM two_factor_recovery_codes
        WHERE user_id = ?
          AND code_hash = ?
          AND used_at IS NULL
        LIMIT 1
    ");

    $recoveryStmt->bind_param(
        'is',
        $userId,
        $codeHash
    );

    $recoveryStmt->execute();

    $recovery =
        $recoveryStmt
            ->get_result()
            ->fetch_assoc();

    if ($recovery) {

        $verified = true;

        $usedRecoveryCodeId =
            (int)$recovery['id'];
    }
}

if (!$verified) {
    api_error(
        'Invalid authentication code.',
        401,
        ['code' => 'INVALID_2FA_CODE']
    );
}

/*
|--------------------------------------------------------------------------
| Consume recovery code if used
|--------------------------------------------------------------------------
*/

if ($usedRecoveryCodeId !== null) {

    $updateRecovery = $db->prepare("
        UPDATE two_factor_recovery_codes
        SET used_at = NOW()
        WHERE id = ?
          AND user_id = ?
          AND used_at IS NULL
    ");

    $updateRecovery->bind_param(
        'ii',
        $usedRecoveryCodeId,
        $userId
    );

    $updateRecovery->execute();

    if (
        $updateRecovery->affected_rows !== 1
    ) {
        api_error(
            'Recovery code has already been used.',
            409
        );
    }
}

/*
|--------------------------------------------------------------------------
| Generate full JWT
|--------------------------------------------------------------------------
*/

$token = jwt_encode([
    'sub' => $userId,
    'email' => $user['email'],
    'ver' => (int)$user['token_version']
]);

unset(
    $user['token_version'],
    $user['two_factor_secret']
);

$user['id'] = (int)$user['id'];
$user['two_factor_enabled'] = true;

api_success(
    [
        'token' => $token,
        'user' => $user,
        'recovery_code_used' =>
            $usedRecoveryCodeId !== null
    ],
    'Two-factor authentication verified successfully.'
);

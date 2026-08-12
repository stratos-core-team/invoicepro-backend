<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/request.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/jwt.php';
require_once __DIR__ . '/../config/database.php';

function require_auth_user(): array
{
    $token = bearer_token();

    if (!$token) {
        api_error(
            'Authentication required.',
            401
        );
    }

    $payload = jwt_decode($token);

    if (
        !$payload
        || empty($payload['sub'])
    ) {
        api_error(
            'Invalid or expired token.',
            401
        );
    }

    $userId =
        (int)$payload['sub'];

    $tokenVersion =
        (int)($payload['ver'] ?? 0);

    $stmt = db()->prepare("
        SELECT
            id,
            full_name,
            business_name,
            email,
            plan,
            status,
            token_version

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
            'User account is unavailable.',
            401
        );
    }

    if ($user['status'] !== 'active') {
        api_error(
            'User account is unavailable.',
            401
        );
    }

    if (
        $tokenVersion !==
        (int)$user['token_version']
    ) {
        api_error(
            'Session is no longer valid.',
            401,
            [
                'code' => 'TOKEN_REVOKED'
            ]
        );
    }

    unset(
        $user['token_version']
    );

    return $user;
}

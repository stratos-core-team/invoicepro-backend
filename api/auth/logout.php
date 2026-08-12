<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(
        'Method not allowed.',
        405
    );
}

$user = require_auth_user();
$db = db();

$stmt = $db->prepare("
    UPDATE users

    SET token_version =
        token_version + 1

    WHERE id = ?
");

$stmt->bind_param(
    'i',
    $user['id']
);

$stmt->execute();

api_success(
    [
        'logged_out' => true
    ],
    'Logged out successfully.'
);

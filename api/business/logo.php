<?php
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed.', 405);
}

$user = require_auth_user();
$db = db();

/*
|--------------------------------------------------------------------------
| Validate uploaded file
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['logo'])
    || !is_array($_FILES['logo'])
    || $_FILES['logo']['error'] !== UPLOAD_ERR_OK
) {
    api_error(
        'A valid logo file is required.',
        422
    );
}

$file = $_FILES['logo'];

$maxSize = 2 * 1024 * 1024; // 2 MB

if ((int)$file['size'] > $maxSize) {
    api_error(
        'Logo must not exceed 2 MB.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Detect MIME type
|--------------------------------------------------------------------------
*/

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mime = $finfo->file(
    $file['tmp_name']
);

$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];

if (!isset($allowedTypes[$mime])) {
    api_error(
        'Only JPG, PNG and WEBP images are allowed.',
        422
    );
}

$extension = $allowedTypes[$mime];

/*
|--------------------------------------------------------------------------
| Prepare upload directory
|--------------------------------------------------------------------------
*/

$uploadDir = dirname(
    __DIR__,
    2
) . '/uploads/business-logos';

if (!is_dir($uploadDir)) {

    if (!mkdir(
        $uploadDir,
        0755,
        true
    )) {
        api_error(
            'Could not create upload directory.',
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Generate safe file name
|--------------------------------------------------------------------------
*/

$fileName =
    'business_' .
    $user['id'] .
    '_' .
    bin2hex(random_bytes(8)) .
    '.' .
    $extension;

$destination =
    $uploadDir .
    '/' .
    $fileName;

/*
|--------------------------------------------------------------------------
| Get existing logo
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT logo
    FROM business_profiles
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $user['id']
);

$stmt->execute();

$currentProfile = $stmt
    ->get_result()
    ->fetch_assoc();

$oldLogo = $currentProfile['logo'] ?? null;

/*
|--------------------------------------------------------------------------
| Move uploaded file
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file(
    $file['tmp_name'],
    $destination
)) {
    api_error(
        'Could not save logo.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Public relative path
|--------------------------------------------------------------------------
*/

$logoPath =
    'uploads/business-logos/' .
    $fileName;

/*
|--------------------------------------------------------------------------
| Save logo path
|--------------------------------------------------------------------------
*/

try {

    if ($currentProfile) {

        $update = $db->prepare("
            UPDATE business_profiles
            SET logo = ?
            WHERE user_id = ?
        ");

        $update->bind_param(
            'si',
            $logoPath,
            $user['id']
        );

        $update->execute();

    } else {

        /*
        |--------------------------------------------------------------------------
        | Create profile if it does not exist
        |--------------------------------------------------------------------------
        */

        $insert = $db->prepare("
            INSERT INTO business_profiles
            (
                user_id,
                business_name,
                email,
                logo
            )
            VALUES (?, ?, ?, ?)
        ");

        $insert->bind_param(
            'isss',
            $user['id'],
            $user['business_name'],
            $user['email'],
            $logoPath
        );

        $insert->execute();
    }

} catch (Throwable $e) {

    if (is_file($destination)) {
        unlink($destination);
    }

    api_error(
        'Could not update business logo.',
        500
    );
}

/*
|--------------------------------------------------------------------------
| Delete old logo after successful update
|--------------------------------------------------------------------------
*/

if (
    $oldLogo
    && str_starts_with(
        $oldLogo,
        'uploads/business-logos/'
    )
) {

    $oldPath =
        dirname(__DIR__, 2)
        . '/'
        . $oldLogo;

    if (
        is_file($oldPath)
        && realpath(dirname($oldPath))
           === realpath($uploadDir)
    ) {
        @unlink($oldPath);
    }
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

api_success([
    'logo' => [
        'path' => $logoPath,
        'mime_type' => $mime,
        'size' => (int)$file['size']
    ]
], 'Business logo uploaded successfully.');

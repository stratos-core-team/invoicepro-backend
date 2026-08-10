<?php
declare(strict_types=1);
require_once __DIR__.'/env.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function db(): mysqli {
    static $db = null;
    if ($db instanceof mysqli) return $db;
    $db = new mysqli(env('DB_HOST','localhost'), env('DB_USER','root'), env('DB_PASS',''), env('DB_NAME','invoicepro_ng'), (int)env('DB_PORT','3306'));
    $db->set_charset('utf8mb4');
    return $db;
}

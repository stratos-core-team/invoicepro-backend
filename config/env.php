<?php
declare(strict_types=1);
function env_load(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key,$value] = explode('=', $line, 2);
        $key = trim($key); $value = trim($value);
        if (($value[0] ?? '') === '"' && str_ends_with($value, '"')) $value = substr($value,1,-1);
        $_ENV[$key] = $value; putenv($key.'='.$value);
    }
}
env_load(dirname(__DIR__).'/.env');
function env(string $key, ?string $default=null): ?string {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : (string)$v;
}

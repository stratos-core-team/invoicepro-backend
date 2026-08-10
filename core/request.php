<?php
declare(strict_types=1);
function request_json(): array {
    $raw=file_get_contents('php://input')?:'';
    if ($raw==='') return [];
    $data=json_decode($raw,true);
    if(!is_array($data)) api_error('Invalid JSON body.',400);
    return $data;
}
function bearer_token(): ?string {
    $h=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/Bearer\s+(.+)/i',$h,$m) ? trim($m[1]) : null;
}

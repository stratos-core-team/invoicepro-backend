<?php
declare(strict_types=1);
require_once __DIR__.'/../core/request.php';
require_once __DIR__.'/../core/response.php';
require_once __DIR__.'/../core/jwt.php';
require_once __DIR__.'/../config/database.php';
function require_auth_user(): array {
    $t=bearer_token(); if(!$t) api_error('Authentication required.',401);
    $p=jwt_decode($t); if(!$p||empty($p['sub'])) api_error('Invalid or expired token.',401);
    $id=(int)$p['sub']; $st=db()->prepare("SELECT id,full_name,business_name,email,plan,status FROM users WHERE id=? LIMIT 1");
    $st->bind_param('i',$id); $st->execute(); $u=$st->get_result()->fetch_assoc();
    if(!$u||$u['status']!=='active') api_error('User account unavailable.',401);
    return $u;
}

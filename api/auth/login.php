<?php
declare(strict_types=1);
require_once __DIR__.'/../../middleware/cors.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../core/response.php';
require_once __DIR__.'/../../core/request.php';
require_once __DIR__.'/../../core/jwt.php';
if($_SERVER['REQUEST_METHOD']!=='POST') api_error('Method not allowed.',405);
$i=request_json(); $e=strtolower(trim((string)($i['email']??''))); $p=(string)($i['password']??'');
$st=db()->prepare("SELECT id,full_name,business_name,email,password_hash,plan,status FROM users WHERE email=? LIMIT 1"); $st->bind_param('s',$e); $st->execute(); $u=$st->get_result()->fetch_assoc();
if(!$u||!password_verify($p,$u['password_hash'])) api_error('Invalid email or password.',401); if($u['status']!=='active') api_error('Account inactive.',403);
$token=jwt_encode(['sub'=>(int)$u['id'],'email'=>$u['email']]); unset($u['password_hash']); api_success(['token'=>$token,'user'=>$u],'Login successful.');

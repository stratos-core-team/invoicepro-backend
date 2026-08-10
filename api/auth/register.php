<?php
declare(strict_types=1);
require_once __DIR__.'/../../middleware/cors.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../core/response.php';
require_once __DIR__.'/../../core/request.php';
require_once __DIR__.'/../../core/jwt.php';
if($_SERVER['REQUEST_METHOD']!=='POST') api_error('Method not allowed.',405);
$i=request_json(); $n=trim((string)($i['full_name']??'')); $b=trim((string)($i['business_name']??'')); $e=strtolower(trim((string)($i['email']??''))); $p=(string)($i['password']??'');
$er=[]; if($n==='')$er['full_name']='Required'; if($b==='')$er['business_name']='Required'; if(!filter_var($e,FILTER_VALIDATE_EMAIL))$er['email']='Invalid email'; if(strlen($p)<8)$er['password']='Minimum 8 characters'; if($er) api_error('Validation failed.',422,$er);
$st=db()->prepare("SELECT id FROM users WHERE email=?"); $st->bind_param('s',$e); $st->execute(); if($st->get_result()->fetch_assoc()) api_error('Email already registered.',409);
$h=password_hash($p,PASSWORD_DEFAULT); $st=db()->prepare("INSERT INTO users(full_name,business_name,email,password_hash,plan,status) VALUES(?,?,?,?, 'free','active')");
$st->bind_param('ssss',$n,$b,$e,$h); $st->execute(); $id=(int)db()->insert_id;
$pr=db()->prepare("INSERT INTO business_profiles(user_id,business_name,email) VALUES(?,?,?)"); $pr->bind_param('iss',$id,$b,$e); $pr->execute();
$token=jwt_encode(['sub'=>$id,'email'=>$e]);
api_success(['token'=>$token,'user'=>['id'=>$id,'full_name'=>$n,'business_name'=>$b,'email'=>$e,'plan'=>'free']],'Account created.',201);

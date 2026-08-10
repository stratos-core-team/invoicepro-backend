<?php
declare(strict_types=1);
require_once __DIR__.'/../../middleware/cors.php';
require_once __DIR__.'/../../middleware/auth.php';
$u=require_auth_user(); $db=db();
if($_SERVER['REQUEST_METHOD']==='GET'){ $q=trim((string)($_GET['q']??'')); if($q!==''){ $l='%'.$q.'%'; $st=$db->prepare("SELECT id,name,email,phone,address,created_at FROM customers WHERE user_id=? AND (name LIKE ? OR email LIKE ? OR phone LIKE ?) ORDER BY id DESC"); $st->bind_param('isss',$u['id'],$l,$l,$l);} else {$st=$db->prepare("SELECT id,name,email,phone,address,created_at FROM customers WHERE user_id=? ORDER BY id DESC");$st->bind_param('i',$u['id']);} $st->execute(); api_success(['customers'=>$st->get_result()->fetch_all(MYSQLI_ASSOC)]);}
if($_SERVER['REQUEST_METHOD']==='POST'){ $i=request_json(); $n=trim((string)($i['name']??'')); if($n==='')api_error('Customer name required.',422); $e=trim((string)($i['email']??''));$p=trim((string)($i['phone']??''));$a=trim((string)($i['address']??''));$st=$db->prepare("INSERT INTO customers(user_id,name,email,phone,address) VALUES(?,?,?,?,?)");$st->bind_param('issss',$u['id'],$n,$e,$p,$a);$st->execute();api_success(['id'=>(int)$db->insert_id],'Customer created.',201);}
api_error('Method not allowed.',405);

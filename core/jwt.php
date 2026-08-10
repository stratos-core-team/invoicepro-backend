<?php
declare(strict_types=1);
require_once __DIR__.'/../config/env.php';
function b64u(string $d): string { return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
function b64ud(string $d): string { $p=strlen($d)%4; if($p)$d.=str_repeat('=',4-$p); return base64_decode(strtr($d,'-_','+/'))?:''; }
function jwt_encode(array $payload): string {
    $header=['alg'=>'HS256','typ'=>'JWT']; $now=time();
    $payload['iat']=$payload['iat']??$now; $payload['exp']=$payload['exp']??($now+(int)env('JWT_TTL_SECONDS','86400'));
    $h=b64u(json_encode($header)); $p=b64u(json_encode($payload)); $s=env('JWT_SECRET');
    if(!$s) throw new RuntimeException('JWT_SECRET missing');
    return "$h.$p.".b64u(hash_hmac('sha256',"$h.$p",$s,true));
}
function jwt_decode(string $token): ?array {
    $parts=explode('.',$token); if(count($parts)!==3)return null; [$h,$p,$s]=$parts; $secret=env('JWT_SECRET'); if(!$secret)return null;
    $exp=b64u(hash_hmac('sha256',"$h.$p",$secret,true)); if(!hash_equals($exp,$s))return null;
    $pl=json_decode(b64ud($p),true); if(!is_array($pl))return null; if(isset($pl['exp'])&&time()>=(int)$pl['exp'])return null; return $pl;
}

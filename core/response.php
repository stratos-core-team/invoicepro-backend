<?php
declare(strict_types=1);
function json_response(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
function api_success(array $data=[],string $message='OK',int $status=200): never {
    json_response(['success'=>true,'message'=>$message,'data'=>$data],$status);
}
function api_error(string $message,int $status=400,array $errors=[]): never {
    json_response(['success'=>false,'message'=>$message,'errors'=>$errors],$status);
}

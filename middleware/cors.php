<?php
declare(strict_types=1);
require_once __DIR__.'/../config/env.php';
header('Access-Control-Allow-Origin: '.env('CORS_ORIGIN','*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

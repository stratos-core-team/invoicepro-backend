<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/core/response.php';
try{db()->query('SELECT 1');api_success(['service'=>'InvoicePro NG Backend','database'=>'ok','time'=>gmdate('c')],'Service healthy.');}catch(Throwable $e){api_error('Service unhealthy.',500);}

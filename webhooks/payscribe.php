<?php
declare(strict_types=1);
require_once __DIR__.'/../middleware/cors.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../core/response.php';
if($_SERVER['REQUEST_METHOD']!=='POST') api_error('Method not allowed.',405);
$raw=file_get_contents('php://input')?:''; if($raw==='')api_error('Empty payload.',400); $event=json_decode($raw,true); if(!is_array($event))api_error('Invalid JSON.',400);
/* TODO: Add official Payscribe signature verification before auto-reconciliation. */
$id=(string)($event['id']??$event['event_id']??hash('sha256',$raw)); $st=db()->prepare("INSERT IGNORE INTO webhook_events(provider,event_id,payload,processed) VALUES('payscribe',?,?,0)");$st->bind_param('ss',$id,$raw);$st->execute();api_success([],'Webhook received.');

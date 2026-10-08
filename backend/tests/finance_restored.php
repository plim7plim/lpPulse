<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PanelApi.php';
$config = require 'C:/BLU/pulse-private/panel-local.php';
$db = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("SET time_zone = '+00:00'");
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'pulse_test') throw new RuntimeException('Only pulse_test is supported.');
$member = $db->query("SELECT cu.company_id,cu.user_id FROM company_users cu JOIN users u ON u.id=cu.user_id WHERE u.email='teste@gmail.com' AND cu.company_id=1001")->fetch(PDO::FETCH_ASSOC);
if (!$member) throw new RuntimeException('Missing local test membership.');
$api = new Pulse\PanelApi($db, str_repeat('x',64));
$property = new ReflectionProperty($api,'session');
$property->setAccessible(true);
$setRole = function(string $role, string $company='1001') use ($property,$api,$member): void {
    $property->setValue($api,['company_id'=>$company,'user_id'=>$member['user_id'],'role'=>$role]);
};
$checks=0;
$check = function(bool $condition) use (&$checks): void { if (!$condition) throw new RuntimeException('Finance check failed: '.($checks+1)); $checks++; };
$reject = function(callable $call,string $code) use ($check): void {
    try { $call(); } catch (Pulse\PanelError $e) { $check($e->errorCode===$code); return; }
    throw new RuntimeException('Missing rejection '.$code);
};
$reject(fn()=>$api->handle('balance','GET',[]),'unauthenticated');
$setRole('viewer');
$reject(fn()=>$api->handle('chipeira','POST',['op'=>'reserve','number'=>'+5511999998888']),'forbidden');
$reject(fn()=>$api->handle('credit_requests','POST',['amount'=>'10.00','method'=>'pix']),'forbidden');
$reject(fn()=>$api->handle('billing_preferences','POST',[]),'forbidden');
$setRole('operator');
$reject(fn()=>$api->handle('credit_requests','POST',['amount'=>'10.00','method'=>'pix']),'forbidden');
$setRole('owner');
$reject(fn()=>$api->handle('balance','POST',[]),'read_only');
$reject(fn()=>$api->handle('credit_requests','DELETE',[]),'method_not_allowed');
$reject(fn()=>$api->handle('credit_requests','POST',['amount'=>'-10.00','method'=>'pix']),'invalid_amount');
$db->beginTransaction();
try {
    $before=$api->handle('balance','GET',[])['balance'];
    $created=$api->handle('credit_requests','POST',['amount'=>'10.00','method'=>'pix']);
    $check($created['status']==='pending' && $created['charge_created']===false);
    $check($api->handle('balance','GET',[])['balance']===$before);
    $items=$api->handle('credit_requests','GET',[])['items'];
    $check((string)$items[0]['id']===(string)$created['id']);
    $api->handle('billing_preferences','POST',['billing_email'=>'teste@gmail.com','responsible_name'=>'Teste financeiro','preferred_method'=>'pix']);
    $check($api->handle('billing_preferences','GET',[])['preferences']['responsible_name']==='Teste financeiro');
    $setRole('owner','2001');
    $check(!in_array((string)$created['id'],array_map(fn($row)=>(string)$row['id'],$api->handle('credit_requests','GET',[])['items']),true));
    $check(is_array($api->handle('invoices','GET',[])['items']));
} finally { $db->rollBack(); }
echo "Finance: $checks checks passed; test writes rolled back.\n";

<?php
declare(strict_types=1);
require_once 'C:/BLU/chipeira/chipeira/integrations/soumaisblu-deploy/api/pulse_stock.php';
$config=require 'C:/BLU/pulse-private/panel-local.php';
if (in_array('--local-root',$argv,true)) {
    $admin=parse_ini_file('C:/BLU/pulse-private/root-client.ini',true,INI_SCANNER_RAW)['client'];
    $config['username']=$admin['user']; $config['password']=$admin['password'];
}
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='pulse_test') throw new RuntimeException('Only pulse_test is supported.');
// Tabela temporária: nenhuma linha real do estoque é alterada.
$db->exec("CREATE TEMPORARY TABLE wa_numbers(id VARCHAR(64) PRIMARY KEY,number VARCHAR(32),status VARCHAR(32),assigned_to VARCHAR(64),blocked_reason TEXT,updated_at DATETIME) ENGINE=InnoDB");
$db->exec("INSERT INTO wa_numbers(id,number,status,assigned_to) VALUES('free','(11) 99999-8888','disponivel',''),('seller','(11) 99999-7777','em_uso','seller-1'),('blocked','(11) 99999-6666','bloqueado','')");
$checks=0;
$check=function(bool $condition)use(&$checks):void{if(!$condition)throw new RuntimeException('Stock check failed');$checks++;};
$call=fn($number,$company,$op='reserve')=>pulse_stock_reserve($db,['number'=>$number,'company_id'=>$company,'op'=>$op]);
$reject=function(callable $call)use($check):void{try{$call();}catch(RuntimeException $e){$check(true);return;}throw new RuntimeException('Expected rejected reserve');};
$reject(fn()=>$call('+5511999997777','1'));
$reject(fn()=>$call('+5511999996666','1'));
$first=$call('+5511999998888','1');$check($first['reserved'] && $first['created']);
$check($db->query("SELECT status FROM wa_numbers WHERE id='free'")->fetchColumn()==='bloqueado');
$check($call('+5511999998888','1')['created']===false);
$reject(fn()=>$call('+5511999998888','2'));
$reject(fn()=>$call('+5511999998888','2','release'));
$check($call('+5511999998888','1','release')['reserved']===false);
$check($call('+5511999998888','2')['created']===true);
echo "Stock: $checks checks passed using only a temporary table.\n";

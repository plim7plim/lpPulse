<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PanelApi.php';
$config=require 'C:/BLU/pulse-private/panel-local.php';
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT DATABASE()')->fetchColumn()!=='pulse_test') throw new RuntimeException('Only local test DB is allowed');
$user=$db->query("SELECT user_id FROM company_users WHERE company_id=1001 LIMIT 1")->fetchColumn();
$api=new Pulse\PanelApi($db,str_repeat('x',64));
$session=new ReflectionProperty($api,'session');
$set=function($role,$company='1001')use($session,$api,$user){$session->setValue($api,['company_id'=>$company,'user_id'=>$user,'role'=>$role]);};
$checks=0;$check=function($ok)use(&$checks){if(!$ok)throw new RuntimeException('Check failed '.($checks+1));$checks++;};
$reject=function($data,$code)use($api,$check){try{$api->handle('number_orders','POST',$data);}catch(Pulse\PanelError $e){$check($e->errorCode===$code);return;}throw new RuntimeException('Expected rejection');};
$id=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
$payload=['request_id'=>$id,'lines'=>[['type'=>'sms','quantity'=>2,'ddd'=>'62','notes'=>'Verificação temporária'],['type'=>'whatsapp','quantity'=>1,'ddd'=>'','notes'=>'']]];
$created=[];
try{
 $set('viewer');$reject($payload,'forbidden');$set('owner');
 $bad=$payload;$bad['lines'][0]['quantity']=0;$reject($bad,'invalid_order');
 $bad=$payload;$bad['lines'][0]['ddd']='629';$reject($bad,'invalid_order');
 $result=$api->handle('number_orders','POST',$payload);$created=$result['ids'];$check(count($created)===2 && $result['charge_created']===false);
 $again=$api->handle('number_orders','POST',$payload);$check($again['replayed']===true && $again['ids']===$created);
 $bad=$payload;$bad['lines'][0]['quantity']=4;$reject($bad,'order_conflict');
 $set('owner','2001');$other=$api->handle('number_orders','GET',[])['items'];$check(!array_intersect($created,array_column($other,'id')));
 echo "Number orders: $checks checks passed; temporary test orders removed.\n";
}finally{
 foreach($created as $rowId){$q=$db->prepare('DELETE FROM service_requests WHERE id=? AND company_id=1001 AND details LIKE ?');$q->execute([$rowId,'%"request_id":"'.$id.'"%']);}
}
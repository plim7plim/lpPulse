<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PanelApi.php';
$config=require 'C:/BLU/pulse-private/panel-local.php';
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='pulse_test')throw new RuntimeException('Only pulse_test.');
$checks=0; $check=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Check failed '.($checks+1));$checks++;};
$reject=function(callable $fn,string $code)use($check){try{$fn();}catch(Pulse\PanelError $e){$check($e->errorCode===$code);return;}throw new RuntimeException('Expected '.$code);};
$db->exec("INSERT INTO companies(name,status) VALUES('Temporary billing verification','active')"); $company=$db->lastInsertId();
$request='test_'.bin2hex(random_bytes(12));
$campaign=null; $finished=false; $ready=false; $offline=false; $rejectRemote=false; $calls=0;
$gateway=new Pulse\ChipeiraGateway(['enabled'=>true,'api_key'=>str_repeat('x',32),'base_url'=>'http://127.0.0.1:3001','allow_local_http'=>true],function($url,$verb,$body)use(&$campaign,&$finished,&$ready,&$offline,&$rejectRemote,&$calls){
    if($offline)throw new Pulse\PanelError('chipeira_offline','Mock timeout',503);
    if($rejectRemote)throw new Pulse\PanelError('chipeira_error','Mock rejected',409);
    if($verb==='POST'){ $calls++; $campaign=['id'=>987654,'status'=>'running','sent_count'=>0];return ['success'=>true,'campaign'=>$campaign]; }
    return ['success'=>true,'campaign'=>['id'=>987654,'status'=>$finished?'completed':'running','sent_count'=>$finished?1:0],'settlementReady'=>$ready];
});
$billing=new Pulse\SmsBilling($db,$gateway);
$payload=['request_id'=>$request,'name'=>'Verification','message'=>'Teste','recipients'=>['+5511999990001','+5511999990002'],'consent'=>true];
try{
    $check(Pulse\SmsBilling::segments(str_repeat('a',160))===1);
    $check(Pulse\SmsBilling::segments(str_repeat('a',161))===2);
    $check(Pulse\SmsBilling::segments(str_repeat('^',81))===2);
    $check(Pulse\SmsBilling::segments(str_repeat('ã',71))===2);
    $check(Pulse\SmsBilling::segments(str_repeat('😀',36))===2);
    $reject(fn()=>$billing->create($company,$payload),'insufficient_balance');
    $q=$db->prepare("INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'credit','1.00','Temporary test',UTC_TIMESTAMP())");$q->execute([$company,hash('sha256',$request)]);
    $quote=$billing->estimate($company,$payload);$check($quote['reserved_cents']===10);
    $billing->create($company,$payload); $check($calls===1);
    $billing->create($company,$payload); $check($calls===1);
    $bad=$payload;$bad['message']='changed';$reject(fn()=>$billing->create($company,$bad),'dispatch_conflict');
    $balance=fn()=>$db->query('SELECT SUM(amount) FROM balance_entries WHERE company_id='.(int)$company)->fetchColumn();
    $check($balance()==='0.90');
    $check($billing->reconcile($company)['pending']===1);
    $finished=true; $check($billing->reconcile($company)['pending']===1); $check($balance()==='0.90');
    $ready=true; $check($billing->reconcile($company)['settled']===1); $check($balance()==='0.95');
    $billing->reconcile($company);$check($balance()==='0.95');
    $payload['request_id']=$request.'_timeout'; $offline=true;
    $reject(fn()=>$billing->create($company,$payload),'chipeira_offline');$check($balance()==='0.85');
    $offline=false;$billing->create($company,$payload);$check($balance()==='0.85');
    $billing->reconcile($company);$check($balance()==='0.90');
    $rejectRemote=true;$payload['request_id']=$request.'_reject';$reject(fn()=>$billing->create($company,$payload),'chipeira_error');$check($balance()==='0.90');
    $reject(fn()=>$billing->create($company,$payload),'dispatch_rejected');$rejectRemote=false;
    $api=new Pulse\PanelApi($db,str_repeat('x',64)); $session=new ReflectionProperty($api,'session');$session->setValue($api,['company_id'=>$company,'user_id'=>'101','role'=>'owner']);
    $reject(fn()=>$api->handle('admin','GET',[]),'forbidden');
    $admin=new Pulse\AdminApi($db,'101',[]);
    $admin->handle('POST',['op'=>'billing','company_id'=>$company,'rate_mills'=>45,'unlimited'=>false,'note'=>'Test tariff']);
    $check($billing->estimate($company,$payload)['reserved_cents']===9);
    $q=$db->prepare("INSERT INTO company_users(company_id,user_id,role,status) VALUES(?,101,'owner','active')");$q->execute([$company]);
    $q=$db->prepare("INSERT INTO credit_requests(company_id,created_by,amount,method) VALUES(?,101,'10.00','pix')");$q->execute([$company]);$credit=$db->lastInsertId();
    $data=['op'=>'credit','company_id'=>$company,'id'=>$credit,'status'=>'paid','note'=>'Mock bank receipt'];
    $admin->handle('POST',$data);$check($balance()==='10.90');
    $admin->handle('POST',$data);$check($balance()==='10.90');
    $data['status']='cancelled';$reject(fn()=>$admin->handle('POST',$data),'credit_closed');
    echo "SMS billing/admin: $checks checks passed. No network or hardware messages sent.\n";
}finally{
    $q=$db->prepare('DELETE FROM admin_events WHERE details LIKE ?');$q->execute(['%"company_id":"'.$company.'"%']);
    foreach(['sms_charges','sms_billing_accounts','balance_entries','credit_requests','company_users'] as $table){$q=$db->prepare('DELETE FROM '.$table.' WHERE company_id=?');$q->execute([$company]);}
    $q=$db->prepare('DELETE FROM companies WHERE id=?');$q->execute([$company]);
}

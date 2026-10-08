<?php
declare(strict_types=1);
require_once __DIR__.'/../src/PanelApi.php';
// No SQL is executed: these checks exercise gates which must run before queries.
class NoDatabase extends PDO { public function __construct() {} }
$api=new Pulse\PanelApi(new NoDatabase(),str_repeat('x',64));
$checks=0;
function rejects(callable $action,string $code): void {
    global $checks; ++$checks;
    try { $action(); } catch (Pulse\PanelError $error) { if ($error->errorCode===$code) return; throw $error; }
    throw new RuntimeException('Expected '.$code);
}
function role(Pulse\PanelApi $api,string $role): void {
    $property=new ReflectionProperty($api,'session'); $property->setAccessible(true);
    $property->setValue($api,['session_id'=>'1','user_id'=>'1','company_id'=>'2','user_name'=>'Test','email'=>'test@example.com','role'=>$role,'company_name'=>'Test']);
}
rejects(fn()=>$api->handle('profile','GET',[]),'unauthenticated');
rejects(fn()=>$api->requireCsrf('invalid'),'unauthenticated');
role($api,'viewer');
rejects(fn()=>$api->handle('requests','POST',['kind'=>'service','op'=>'create']),'forbidden');
rejects(fn()=>$api->handle('tickets','POST',['op'=>'close','id'=>'1']),'forbidden');
rejects(fn()=>$api->handle('users','GET',[]),'forbidden');
rejects(fn()=>$api->requireCsrf('invalid'),'csrf_failed');
$api->requireCsrf($api->csrfToken()); ++$checks;
role($api,'operator');
rejects(fn()=>$api->handle('profile','POST',['name'=>'Changed']),'forbidden');
rejects(fn()=>$api->handle('credit_requests','POST',['amount'=>'100.00','method'=>'pix']),'forbidden');
rejects(fn()=>$api->handle('billing_preferences','POST',[]),'forbidden');
rejects(fn()=>$api->handle('requests','POST',['kind'=>'partnership','op'=>'create']),'forbidden');
role($api,'manager');
$user=['op'=>'create','name'=>'User','email'=>'new@example.com','password'=>'validpassword123','role'=>'owner'];
rejects(fn()=>$api->handle('users','POST',$user),'invalid_choice');
$user['role']='manager';
rejects(fn()=>$api->handle('users','POST',$user),'invalid_choice');
role($api,'owner');
$user['role']='owner';
rejects(fn()=>$api->handle('users','POST',$user),'invalid_choice');
rejects(fn()=>$api->handle('tickets','POST',['op'=>'create','attachments'=>[['name'=>'x']]]),'uploads_unavailable');
rejects(fn()=>$api->handle('balance','POST',[]),'read_only');
echo "Panel policy: $checks checks passed (no database).\n";

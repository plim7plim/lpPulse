<?php
declare(strict_types=1);
require_once __DIR__.'/../src/AccountAccess.php';
require_once __DIR__.'/../src/PanelApi.php';
$config=require 'C:/BLU/pulse-private/panel-local.php';
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='pulse_test')throw new RuntimeException('Only pulse_test');
$fixture=bin2hex(random_bytes(10));$email='account-'.$fixture.'@example.test';$unknown='unknown-'.$fixture.'@example.test';
$dir='C:/BLU/pulse-private/auth-test-'.$fixture;mkdir($dir,0700);
$config['local_test']=true;$config['auth_mail']=['transport'=>'file','directory'=>$dir];
$auth=new Pulse\AccountAccess($db,$config);$checks=0;$user=null;$company=null;$keys=[];$calls=0;
$check=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Failed check '.($checks+1));$checks++;};
$reject=function(callable $fn,string $code)use($check){try{$fn();}catch(Pulse\PanelError $e){$check($e->errorCode===$code);return;}throw new RuntimeException('Expected '.$code);};
$call=function(string $action,array $data)use($auth,$config,$fixture,&$keys,&$calls){
    $ip='fixture-'.$fixture.'-'.(++$calls);
    $scope=in_array($action,['register','forgot_password'],true)?strtolower($data['email']):hash('sha256',$data['token']);
    foreach(['ip:'.$ip,'email:'.$scope] as $part)$keys[]=hash_hmac('sha256','account:'.$action.':'.$part,$config['api_secret']);
    return $auth->handle($action,$data,$ip);
};
$findToken=function(string $purpose)use($dir): string {
    $files=glob($dir.'/*.eml');usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));
    foreach($files as $file)if(preg_match('/#'.$purpose.'=([a-f0-9]{64})/',file_get_contents($file),$m))return $m[1];
    throw new RuntimeException('Missing email token');
};
$password='My private test phrase 123!';
try{
    $result=$call('register',['name'=>'Teste de cadastro','company'=>'Empresa temporária','email'=>$email,'role'=>'admin','unlimited'=>true]);
    $q=$db->prepare('SELECT u.*,cu.company_id,cu.role FROM users u JOIN company_users cu ON cu.user_id=u.id WHERE u.email=?');$q->execute([$email]);$row=$q->fetch(PDO::FETCH_ASSOC);$user=$row['id'];$company=$row['company_id'];
    $check($row['status']==='pending' && $row['role']==='owner');$check(count(glob($dir.'/*.eml'))===1);
    $verify=$findToken('verify');$q=$db->prepare('SELECT token_hash FROM email_verification_tokens WHERE user_id=?');$q->execute([$user]);$check($q->fetchColumn()===hash('sha256',$verify));
    $panel=new Pulse\PanelApi($db,$config['api_secret']);
    $loginIp='login-'.$fixture;$keys[]=hash_hmac('sha256',$loginIp.'|'.$email,$config['api_secret']);
    $reject(fn()=>$panel->login(['email'=>$email,'password'=>$password],$loginIp),'login_failed');
    $reject(fn()=>$call('verify_email',['token'=>$verify,'password'=>$password,'password_confirmation'=>'different']),'password_mismatch');
    $call('verify_email',['token'=>$verify,'password'=>$password,'password_confirmation'=>$password]);
    $check($db->query('SELECT status FROM users WHERE id='.(int)$user)->fetchColumn()==='active');
    $check($db->query('SELECT status FROM companies WHERE id='.(int)$company)->fetchColumn()==='active');
    $check($db->query('SELECT email_verified_at FROM users WHERE id='.(int)$user)->fetchColumn()!==null);
    $reject(fn()=>$call('verify_email',['token'=>$verify,'password'=>$password,'password_confirmation'=>$password]),'invalid_token');
    $session=$panel->login(['email'=>$email,'password'=>$password],$loginIp);$check($panel->authenticate($session));$check(!$panel->sessionInfo()['is_admin']);
    $check((float)$panel->handle('balance','GET',[])['balance']===0.0);
    $check($db->query('SELECT COUNT(*) FROM sms_billing_accounts WHERE company_id='.(int)$company)->fetchColumn()==0);
    $known=$call('forgot_password',['email'=>$email]);$count=count(glob($dir.'/*.eml'));
    $missing=$call('forgot_password',['email'=>$unknown]);$check($known===$missing && count(glob($dir.'/*.eml'))===$count);
    $reset=$findToken('reset');
    $reject(fn()=>$call('reset_password',['token'=>$reset,'password'=>'short','password_confirmation'=>'short']),'invalid_password');
    $new='Another private phrase 456!';
    $call('reset_password',['token'=>$reset,'password'=>$new,'password_confirmation'=>$new]);
    $check(!(new Pulse\PanelApi($db,$config['api_secret']))->authenticate($session));
    $reject(fn()=>$call('reset_password',['token'=>$reset,'password'=>$new,'password_confirmation'=>$new]),'invalid_token');
    $reject(fn()=>$panel->login(['email'=>$email,'password'=>$password],$loginIp),'login_failed');
    $panel->login(['email'=>$email,'password'=>$new],$loginIp);$check($panel->sessionInfo()['authenticated']);
    $call('forgot_password',['email'=>$email]);
    $q=$db->prepare('SELECT token_hash FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL');$q->execute([$user]);$activeHash=$q->fetchColumn();
    $expired='';foreach(glob($dir.'/*.eml') as $file){if(preg_match('/#reset=([a-f0-9]{64})/',file_get_contents($file),$m)&&hash('sha256',$m[1])===$activeHash)$expired=$m[1];}
    $db->exec('UPDATE password_reset_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE user_id='.(int)$user);
    $reject(fn()=>$call('reset_password',['token'=>$expired,'password'=>$password,'password_confirmation'=>$password]),'invalid_token');
    $db->exec("UPDATE users SET status='blocked' WHERE id=".(int)$user);$count=count(glob($dir.'/*.eml'));$call('forgot_password',['email'=>$email]);$check(count(glob($dir.'/*.eml'))===$count);
    $duplicate=$call('register',['name'=>'Outra pessoa','company'=>'Outro negócio','email'=>$email]);$check($duplicate===$result);
    $q=$db->prepare('SELECT name FROM users WHERE id=?');$q->execute([$user]);$check($q->fetchColumn()==='Teste de cadastro');
    $throttleIp='throttle-'.$fixture;
    for($i=0;$i<6;$i++){
        $target='throttle-'.$fixture.'-'.$i.'@example.test';
        foreach(['ip:'.$throttleIp,'email:'.$target] as $scope)$keys[]=hash_hmac('sha256','account:forgot_password:'.$scope,$config['api_secret']);
        if($i<5)$auth->handle('forgot_password',['email'=>$target],$throttleIp);
        else $reject(fn()=>$auth->handle('forgot_password',['email'=>$target],$throttleIp),'rate_limited');
    }
    $unconfigured=$config;$unconfigured['auth_mail']=[];
    $reject(fn()=>(new Pulse\AuthEmail($unconfigured))->ready(),'email_not_configured');
    echo "Account access: $checks checks passed. No real email or SMS sent.\n";
}finally{
    if($user){foreach(['auth_sessions','password_reset_tokens','email_verification_tokens','company_users'] as $table){$q=$db->prepare('DELETE FROM '.$table.' WHERE user_id=?');$q->execute([$user]);}$q=$db->prepare('DELETE FROM users WHERE id=?');$q->execute([$user]);}
    if($company){$q=$db->prepare('DELETE FROM companies WHERE id=?');$q->execute([$company]);}
    foreach($keys as $key){$q=$db->prepare('DELETE FROM auth_login_attempts WHERE attempt_key=?');$q->execute([$key]);}
    foreach(glob($dir.'/*.eml') as $file)unlink($file);rmdir($dir);
}

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/src/ChipeiraGateway.php';
require_once __DIR__.'/../backend/src/AuthEmail.php';
$checks=[];
$checks['PHP 7.4+']=version_compare(PHP_VERSION,'7.4','>=');
foreach(['pdo_mysql','curl','iconv'] as $extension) $checks['Extensão '.$extension]=extension_loaded($extension);
$path=getenv('PULSE_CONFIG_PATH'); $resolved=$path?realpath($path):false;
$config=$resolved?require $resolved:[];
$publicRoot=realpath($config['public_root']??__DIR__.'/..');
$root=str_replace('\\','/',$publicRoot?:__DIR__.'/..').'/';
$checks['Configuração fora do projeto']=$resolved && strpos(strtolower(str_replace('\\','/',$resolved)),strtolower($root))!==0;
if($checks['Configuração fora do projeto']) {
    $checks['Pasta pública definida']=!empty($config['public_root']) && $publicRoot!==false;
    $checks['Origem HTTPS']=strtolower((string)parse_url($config['api_origin']??'',PHP_URL_SCHEME))==='https';
    $checks['Cookie seguro']=($config['cookie_secure']??false)===true;
    $checks['Modo de teste desativado']=($config['local_test']??false)!==true;
    $checks['Segredo de sessão configurado']=strlen($config['api_secret']??'')>=32;
    $checks['Administrador definido']=!empty($config['admin_user_ids']);
    try {
        $db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        foreach(['auth_sessions','password_reset_tokens','email_verification_tokens','balance_entries','sms_charges','sms_billing_accounts','admin_events'] as $table) $db->query('SELECT 1 FROM '.$table.' LIMIT 1');
        $checks['Banco e migrações 006/007']=true;
        $checks['Sem contas ilimitadas']=$db->query('SELECT COUNT(*) FROM sms_billing_accounts WHERE unlimited=1')->fetchColumn()==0;
        $admins=$config['admin_user_ids']??[]; $checks['Administrador ativo']=false;
        foreach($admins as $id) { $q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active' AND deleted_at IS NULL");$q->execute([$id]);if($q->fetchColumn())$checks['Administrador ativo']=true; }
    } catch(Throwable $e) { $checks['Banco e migrações 006/007']=false; }
    try {(new Pulse\AuthEmail($config))->ready();$checks['E-mail de autenticação configurado']=($config['auth_mail']['transport']??'')==='mail';}catch(Throwable $e){$checks['E-mail de autenticação configurado']=false;}
    try {
        Pulse\ChipeiraGateway::validateBaseUrl($config['chipeira']['base_url']??'',false);
        $status=(new Pulse\ChipeiraGateway($config['chipeira']??[]))->handle('1','GET',['op'=>'status']);
        $checks['Chipeira acessível por HTTPS fixo']=($status['connected']??false)===true;
    } catch(Throwable $e) { $checks['Chipeira acessível por HTTPS fixo']=false; }
}
foreach($checks as $name=>$ok) echo ($ok?'OK  ':'FALHA ').$name."\n";
exit(in_array(false,$checks,true)?1:0);

<?php
declare(strict_types=1);
require_once __DIR__.'/src/PanelApi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function panelRespond(int $status, array $body): void {
    http_response_code($status); echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function panelCookie(string $token, bool $secure): void {
    setcookie('pulse_session',$token,['expires'=>$token===''?time()-3600:time()+28800,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
}
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); throw new \Pulse\PanelError('method_not_allowed','Método não permitido.',405); }
    $path=getenv('PULSE_CONFIG_PATH'); $resolved=$path?realpath($path):false;
    $webroot=realpath(dirname(__DIR__));
    $documentRoot=realpath($_SERVER['DOCUMENT_ROOT']??dirname(__DIR__));
    $normal=function(string $path): string { return strtolower(str_replace('\\','/',$path)); };
    if (!$resolved || !$webroot || strpos($normal($resolved),$normal($webroot).'/')===0 || ($documentRoot && strpos($normal($resolved),$normal($documentRoot).'/')===0)) throw new \Pulse\PanelError('not_configured','API ainda não configurada.',503);
    $config=require $resolved;
    if (!is_array($config) || empty($config['dsn']) || empty($config['username']) || strlen($config['api_secret']??'')<32 || empty($config['api_origin'])) throw new \Pulse\PanelError('not_configured','API ainda não configurada.',503);
    $secure=($config['cookie_secure']??true)===true;
    $origin=rtrim($config['api_origin'],'/');
    if (!filter_var($origin,FILTER_VALIDATE_URL) || (!$secure && !in_array(parse_url($origin,PHP_URL_HOST),['localhost','127.0.0.1','::1'],true)) || ($secure && parse_url($origin,PHP_URL_SCHEME)!=='https')) throw new \Pulse\PanelError('not_configured','Origem segura não configurada.',503);
    if ($secure && strtolower($_SERVER['HTTPS']??'')!=='on' && ($_SERVER['SERVER_PORT']??'')!=='443') throw new \Pulse\PanelError('https_required','Use HTTPS.',403);
    $data=$_GET;
    if ($method==='POST') {
        if (($_SERVER['HTTP_ORIGIN']??'')!==$origin) throw new \Pulse\PanelError('origin_failed','Origem da requisição inválida.',403);
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') throw new \Pulse\PanelError('json_required','Envie JSON.',415);
        $raw=file_get_contents('php://input',false,null,0,65537);
        if ($raw===false || strlen($raw)>65536) throw new \Pulse\PanelError('body_too_large','Requisição muito grande.',413);
        try { $decoded=json_decode($raw,false,32,JSON_THROW_ON_ERROR); } catch (\JsonException $error) { throw new \Pulse\PanelError('invalid_json','JSON inválido.',400); }
        if (!$decoded instanceof \stdClass) throw new \Pulse\PanelError('invalid_json','Envie um objeto JSON.',400);
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    }
    $action=$_GET['action']??'session';
    if (!is_string($action) || !preg_match('/^[a-z_]{1,32}$/D',$action)) throw new \Pulse\PanelError('invalid_action','Recurso inválido.',400);
    $db=new PDO($config['dsn'],$config['username'],$config['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true]);
    $db->exec("SET time_zone = '+00:00'");
    $api=new \Pulse\PanelApi($db,$config['api_secret'],$config['chipeira']??[],['local_test'=>($config['local_test']??false)===true,'test_emails'=>$config['test_number_selection_emails']??[]]);
    $token=$_COOKIE['pulse_session']??'';
    if (is_string($token)) $api->authenticate($token);
    if ($action==='login') {
        if ($method!=='POST') throw new \Pulse\PanelError('method_not_allowed','Use POST.',405);
        $token=$api->login($data,$_SERVER['REMOTE_ADDR']??'unknown'); panelCookie($token,$secure); $result=$api->sessionInfo();
    } else {
        if ($method==='POST') $api->requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        $result=$api->handle($action,$method,$data);
        if ($action==='logout') panelCookie('',$secure);
    }
    if (in_array($action,['session','login'],true) && ($config['local_test']??false)===true) $result['test_environment']=true;
    panelRespond(200,['ok'=>true,'data'=>$result]);
} catch (\Pulse\PanelError $error) {
    panelRespond($error->httpStatus,['ok'=>false,'error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]]);
} catch (Throwable $error) {
    error_log('Pulse API: '.get_class($error));
    panelRespond(503,['ok'=>false,'error'=>['code'=>'unavailable','message'=>'Não foi possível concluir. Tente novamente.']]);
}

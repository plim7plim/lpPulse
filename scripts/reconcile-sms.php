<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/src/SmsBilling.php';
$path=getenv('PULSE_CONFIG_PATH');
if (!$path || !is_file($path)) throw new RuntimeException('Defina PULSE_CONFIG_PATH.');
$config=require $path;
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$billing=new Pulse\SmsBilling($db,new Pulse\ChipeiraGateway($config['chipeira']??[]));
$companies=$db->query("SELECT DISTINCT company_id FROM sms_charges WHERE status IN ('reserved','running')")->fetchAll(PDO::FETCH_COLUMN);
foreach ($companies as $company) {
    $result=$billing->reconcile((string)$company);
    echo 'Empresa '.$company.': '.$result['settled'].' conferidas; '.$result['pending']." pendentes.\n";
}

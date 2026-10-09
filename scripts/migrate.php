<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$path=getenv('PULSE_CONFIG_PATH');
if (!$path || !is_file($path)) throw new RuntimeException('Defina PULSE_CONFIG_PATH para o arquivo privado.');
$config=require $path;
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// Migração incremental: não importa schema.sql nem altera dados existentes.
$db->exec(file_get_contents(__DIR__.'/../database/migrations/006_sms_billing_admin.sql'));
$db->exec(file_get_contents(__DIR__.'/../database/migrations/007_registration.sql'));
echo "Migrações 006 e 007 aplicadas.\n";

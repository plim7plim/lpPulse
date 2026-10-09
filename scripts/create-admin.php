<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$path=getenv('PULSE_CONFIG_PATH');
if (!$path || !is_file($path)) throw new RuntimeException('Defina PULSE_CONFIG_PATH.');
$config=require $path;
$email=strtolower(trim(getenv('PULSE_ADMIN_EMAIL')?:''));
$password=getenv('PULSE_ADMIN_PASSWORD')?:'';
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) throw new RuntimeException('Defina PULSE_ADMIN_EMAIL e PULSE_ADMIN_PASSWORD (mínimo 12 caracteres), apenas neste processo.');
$db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$query=$db->prepare('SELECT id FROM users WHERE email=?'); $query->execute([$email]);
if ($query->fetchColumn()) throw new RuntimeException('E-mail já cadastrado. Para promover uma conta existente, inclua seu ID em admin_user_ids no arquivo privado.');
$db->beginTransaction();
try {
    $query=$db->prepare("INSERT INTO companies(name,status) VALUES('Operação Pulse','active')"); $query->execute(); $company=$db->lastInsertId();
    $query=$db->prepare("INSERT INTO users(name,email,password_hash,status) VALUES('Administração Pulse',?,?,'active')"); $query->execute([$email,password_hash($password,PASSWORD_DEFAULT)]); $user=$db->lastInsertId();
    $query=$db->prepare("INSERT INTO company_users(company_id,user_id,role,status) VALUES(?,?,'owner','active')"); $query->execute([$company,$user]);
    $db->commit();
    echo 'Conta criada. Adicione o ID '.$user." a admin_user_ids no arquivo privado. Senha não foi gravada em arquivos.\n";
} catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }

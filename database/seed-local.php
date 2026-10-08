<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$privatePath = $argv[1] ?? 'C:/BLU/pulse-private/panel-local.php';
$config = require $privatePath;
if (($config['local_test'] ?? false) !== true || $config['dsn'] !== 'mysql:host=127.0.0.1;port=3307;dbname=pulse_test;charset=utf8mb4') throw new RuntimeException('Use somente a configuração do banco local pulse_test.');
$root = parse_ini_file(dirname($privatePath).'/root-client.ini', true)['client'];
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false];
$admin = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4','root',$root['password'],$options);
$admin->exec('CREATE DATABASE IF NOT EXISTS pulse_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->exec("CREATE USER IF NOT EXISTS 'pulse_test'@'127.0.0.1' IDENTIFIED BY ".$admin->quote($config['password']));
$admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON pulse_test.* TO 'pulse_test'@'127.0.0.1'");
$admin->exec('USE pulse_test');
$schema = file_get_contents(dirname(__DIR__).'/schema.sql');
preg_match_all('/^CREATE TABLE ([a-z_]+) /m', $schema, $expected);
$actual = $admin->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!$actual) $admin->exec($schema);
elseif (array_diff($expected[1], $actual)) throw new RuntimeException('Estrutura parcial encontrada. Confira antes de reimportar; nenhum dado foi apagado.');
$db = new PDO($config['dsn'],$config['username'],$config['password'],$options);
$db->exec("SET time_zone = '+00:00'");
$run = static function(string $sql, array $values=[]) use ($db): void { $statement=$db->prepare($sql); $statement->execute($values); };
if ((int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn() > 0) {
    echo "Banco local já contém dados. Seed preservado, sem sobrescrever testes.\n"; exit;
}
$db->beginTransaction();
try {
    $password = password_hash('PulseTeste123!',PASSWORD_DEFAULT);
    $run("INSERT INTO companies (id,name,legal_name,contact_email,status,city,state_code,profile_completed_at) VALUES (1001,'Pulse · empresa de teste','Empresa de Teste Local','admin@pulse.test','active','São Paulo','SP',UTC_TIMESTAMP()),(2001,'Segunda empresa de teste','Outro Cliente Local','outro@pulse.test','active','Campinas','SP',UTC_TIMESTAMP())");
    foreach ([[101,'Administrador de teste','admin@pulse.test'],[102,'Operador de teste','operador@pulse.test'],[103,'Leitor de teste','leitura@pulse.test'],[201,'Outro cliente de teste','outro@pulse.test']] as $user) {
        $run("INSERT INTO users (id,name,email,password_hash,status,email_verified_at) VALUES (?,?,?,?,'active',UTC_TIMESTAMP())",array_merge($user,[$password]));
    }
    $run("INSERT INTO company_users (company_id,user_id,role,status) VALUES (1001,101,'owner','active'),(1001,102,'operator','active'),(1001,103,'viewer','active'),(2001,201,'owner','active')");
    $run("INSERT INTO billing_preferences (company_id,billing_email,responsible_name,preferred_method) VALUES (1001,'admin@pulse.test','Administrador de teste','pix')");
    foreach ([[1001,101,'credit','1000.00','Crédito fictício para testar o painel'],[1001,101,'debit','-125.00','Consumo fictício de campanha'],[2001,201,'credit','50.00','Saldo da segunda empresa de teste']] as $index=>$entry) {
        $run('INSERT INTO balance_entries (company_id,entry_key,type,amount,description,occurred_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())',[$entry[0],hash('sha256','pulse-local-seed-'.$index),$entry[2],$entry[3],$entry[4]]);
    }
    $run("INSERT INTO credit_requests (id,company_id,created_by,amount,method,status) VALUES (1001,1001,101,200.00,'pix','pending')");
    $run("INSERT INTO invoices (company_id,reference,description,amount,status,due_date,paid_at) VALUES (1001,'TESTE-001','Fatura fictícia · teste de consulta',125.00,'paid',DATE_SUB(UTC_DATE(),INTERVAL 3 DAY),UTC_TIMESTAMP()),(1001,'TESTE-002','Fatura fictícia · teste de vencimento',250.00,'open',DATE_ADD(UTC_DATE(),INTERVAL 7 DAY),NULL),(1001,'TESTE-003','Fatura fictícia · teste de atraso',75.00,'overdue',DATE_SUB(UTC_DATE(),INTERVAL 5 DAY),NULL)");
    $run("INSERT INTO support_tickets (id,company_id,created_by,subject,category,contact_email,status) VALUES (1001,1001,101,'Teste de atendimento pelo painel','technical','admin@pulse.test','open'),(1002,1001,101,'Teste de consulta financeira','financial','admin@pulse.test','closed'),(2001,2001,201,'Ticket privado da segunda empresa','other','outro@pulse.test','open')");
    $run("INSERT INTO support_messages (company_id,ticket_id,author_id,body) VALUES (1001,1001,101,'Mensagem fictícia para testar respostas e encerramento.'),(1001,1002,101,'Consulta fictícia encerrada.'),(2001,2001,201,'Este conteúdo não deve aparecer para a empresa 1001.')");
    $run('INSERT INTO service_requests (company_id,created_by,service,details,status) VALUES (1001,101,?,?,?)',['sms_number',json_encode(['ddd'=>'11','notes'=>'Solicitação fictícia. Nenhum número foi contratado.'],JSON_UNESCAPED_UNICODE),'submitted']);
    $run('INSERT INTO service_requests (company_id,created_by,service,details,status) VALUES (1001,101,?,?,?)',['mobile_plan',json_encode(['plan'=>'Consulta de plano para teste','contact'=>'admin@pulse.test'],JSON_UNESCAPED_UNICODE),'reviewing']);
    $run('INSERT INTO partnership_requests (company_id,created_by,type,details,status) VALUES (1001,101,?,?,?)',['affiliate',json_encode(['name'=>'Parceiro de teste','email'=>'admin@pulse.test']),'submitted']);
    $run("INSERT INTO notifications (company_id,user_id,ticket_id,title,body) VALUES (1001,101,1001,'Ambiente de teste preparado','Dados fictícios. Nenhum pagamento ou disparo real está ativo.')");
    $db->commit();
} catch (Throwable $error) { $db->rollBack(); throw $error; }
echo 'Schema importado: '.count($expected[1])." tabelas. Duas empresas, quatro usuários e dados fictícios criados.\n";

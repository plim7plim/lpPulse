<?php
declare(strict_types=1);

require_once __DIR__ . '/src/PaymentGateway.php';
require_once __DIR__ . '/src/PaymentValidator.php';
require_once __DIR__ . '/src/PaymentConfirmation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Método não permitido.']);
}
$path = getenv('PULSE_CONFIG_PATH');
if (!$path || !is_file($path)) respond(503, ['error' => 'Pagamentos ainda não configurados.']);
$config = require $path;
$gateway = $config['gateway'] ?? null;
if (!$gateway instanceof \Pulse\PaymentGateway || empty($config['merchant_id'])) {
    respond(503, ['error' => 'Provedor ainda não configurado.']);
}
$body = file_get_contents('php://input', false, null, 0, 1048577);
if ($body === false || strlen($body) > 1048576) respond(413, ['error' => 'Notificação muito grande.']);
try {
    $id = $gateway->authenticateWebhook($body, getallheaders());
} catch (Throwable $error) {
    respond(401, ['error' => 'Notificação não autenticada.']);
}
try {
    $db = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("SET time_zone = '+00:00'");
    $result = (new \Pulse\PaymentConfirmation($db, $gateway, $config['merchant_id']))->reconcile($id);
    respond(200, ['received' => true, 'status' => $result['status']]);
} catch (Throwable $error) {
    // Não retornar detalhes SQL, tokens ou dados do provedor ao solicitante.
    error_log('Pulse: falha na reconciliação de pagamento; tipo=' . get_class($error));
    respond(503, ['error' => 'Não foi possível confirmar o pagamento. Tente novamente.']);
}

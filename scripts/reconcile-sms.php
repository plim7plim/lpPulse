<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require_once __DIR__ . "/../backend/src/SmsBilling.php";
require_once __DIR__ . "/../backend/src/NumberDelivery.php";
require_once __DIR__ . "/../backend/src/NumberContracts.php";
require_once __DIR__ . "/../backend/src/EfiPayments.php";
$path = getenv("PULSE_CONFIG_PATH");
if (!$path || !is_file($path)) {
    throw new RuntimeException("Defina PULSE_CONFIG_PATH.");
}
$config = require $path;
if (
    !empty($config["efi"]["enabled"]) &&
    ($config["efi"]["environment"] ?? "homologacao") === "homologacao" &&
    ($config["local_test"] ?? false) !== true
) {
    throw new RuntimeException("Pix de homologação bloqueado fora do ambiente de testes.");
}
$lock = fopen(dirname(realpath($path)) . "/pulse-reconciliation.lock", "c");
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Conferência já em execução ou diretório privado sem permissão.\n");
    exit(1);
}
$watch = in_array("--watch", $argv, true);
do {
    try {
        $db = new PDO($config["dsn"], $config["username"], $config["password"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $db->exec("SET time_zone='+00:00'");
        (new Pulse\EfiPayments($db, $config["efi"] ?? []))->reconcilePending();
        $gateway = new Pulse\ChipeiraGateway($config["chipeira"] ?? []);
        $billing = new Pulse\SmsBilling($db, $gateway);
        $delivery = new Pulse\NumberDelivery($db, $gateway);
        $contracts = new Pulse\NumberContracts($db, $gateway);
        $contracts->expire();
        $companies = $db
            ->query(
                "SELECT DISTINCT company_id FROM sms_charges WHERE status IN ('reserved','running') UNION SELECT DISTINCT company_id FROM number_inventory WHERE status IN ('allocated','activating') UNION SELECT company_id FROM number_contracts WHERE status IN ('paid','active')",
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($companies as $company) {
            try {
                $sms = $billing->reconcile((string) $company);
                $numbers = $delivery->deliver((string) $company);
                $contracts->sync((string) $company);
                echo gmdate("c") .
                    " Empresa " .
                    $company .
                    ": SMS " .
                    $sms["settled"] .
                    " conferidos, " .
                    $sms["pending"] .
                    " pendentes; números " .
                    $numbers["delivered"] .
                    " entregues, " .
                    $numbers["pending"] .
                    " pendentes.\n";
            } catch (Throwable $e) {
                fwrite(
                    STDERR,
                    gmdate("c") .
                        " Conferência da empresa pendente; próxima execução tentará novamente.\n",
                );
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, gmdate("c") . " Banco ou migrações indisponíveis.\n");
        if (!$watch) {
            exit(1);
        }
    }
    if ($watch) {
        sleep(30);
    }
} while ($watch);
flock($lock, LOCK_UN);
fclose($lock);

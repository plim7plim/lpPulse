<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/PanelApi.php";

$checks = 0;
$check = function (bool $ok) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException("Efí check " . ($checks + 1) . " failed.");
    }
    $checks++;
};
$reject = function (callable $call) use ($check): void {
    try {
        $call();
    } catch (Throwable $error) {
        $check(true);
        return;
    }
    throw new RuntimeException("Expected rejection.");
};
$local = require "C:/BLU/pulse-private/panel-local.php";
$settings = [
    "enabled" => true,
    "environment" => "homologacao",
    "pix_key" => "fixture-key",
    "homologacao" => [
        "client_id" => "fixture",
        "client_secret" => "fixture",
        "certificate" => __FILE__,
    ],
];
$cobs = [];
$calls = [];
$transport = function ($method, $path, $body) use (&$cobs, &$calls) {
    $calls[] = [$method, $path];
    if (strpos($path, "/v2/loc/") === 0) {
        return ["qrcode" => "fixture-copy", "imagemQrcode" => "data:image/png;base64,AAAA"];
    }
    $id = substr($path, strlen("/v2/cob/"));
    if ($method === "PUT") {
        if (isset($cobs[$id])) {
            throw new RuntimeException("Duplicate PUT");
        }
        $cobs[$id] = $body + [
            "txid" => $id,
            "status" => "ATIVA",
            "pixCopiaECola" => "fixture-copy",
            "loc" => ["id" => 1],
        ];
        $cobs[$id]["calendario"]["criacao"] = gmdate("c");
    }
    if (!isset($cobs[$id])) {
        throw new RuntimeException("Not found");
    }
    return $cobs[$id];
};
$gateway = new Pulse\EfiPix($settings, $transport);
$check($gateway->ready());
$check($gateway->name() === "efi_pix_homologacao");
$reject(fn() => new Pulse\EfiPix(["environment" => "unknown"]));
$reject(fn() => $gateway->fetchPayment("../other"));
$reject(fn() => $gateway->authenticateWebhook('{"pix":[]}', []));

// Todas as alterações ficam na transação externa, inclusive commits do código testado.
$db = new class ($local["dsn"], $local["username"], $local["password"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]) extends PDO {
    public function beginTransaction(): bool
    {
        return $this->inTransaction() ? true : parent::beginTransaction();
    }
    public function commit(): bool
    {
        return true;
    }
};
if ($db->query("SELECT DATABASE()")->fetchColumn() !== "pulse_test") {
    throw new RuntimeException("Only pulse_test.");
}
$db->beginTransaction();
try {
    $db->exec("INSERT INTO companies(name,status) VALUES('Efí temporary fixture','active')");
    $company = $db->lastInsertId();
    $user = $db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    $membership = $db->prepare(
        "INSERT INTO company_users(company_id,user_id,role) VALUES(?,?,'owner')",
    );
    $membership->execute([$company, $user]);
    $payments = new Pulse\EfiPayments($db, $settings, $gateway);
    $create = function (bool $sms) use ($db, $company, $user) {
        $q = $db->prepare(
            "INSERT INTO credit_requests(company_id,created_by,amount,method) VALUES(?,?,?,'pix')",
        );
        $q->execute([$company, $user, "6.00"]);
        $id = $db->lastInsertId();
        if ($sms) {
            $q = $db->prepare(
                "INSERT INTO sms_purchases(company_id,credit_request_id,quantity,rate_mills,channel) VALUES(?,?,100,60,'sms')",
            );
            $q->execute([$company, $id]);
        }
        return $id;
    };
    foreach ([false, true] as $sms) {
        $id = $create($sms);
        $first = $payments->checkout($company, $id);
        $again = $payments->checkout($company, $id);
        $check($first["pix"]["txid"] === $again["pix"]["txid"]);
        $check($first["pix"]["copy_paste"] === "fixture-copy");
        $txid = $first["pix"]["txid"];
        $check($payments->reconcile($company, $id)["status"] === "pending");
        $cobs[$txid]["status"] = "CONCLUIDA";
        $cobs[$txid]["pix"] = [["endToEndId" => "fixture-receipt", "valor" => "6.00"]];
        $check($payments->reconcile($company, $id)["status"] === "approved");
        $check($payments->reconcile($company, $id)["credited_amount"] === "6.00");
        $reject(fn() => $payments->reconcile("0", $id));
        if ($sms) {
            $check(Pulse\SmsPurchases::available($db, $company) === 100);
        } else {
            $check(
                (string) $db
                    ->query(
                        "SELECT SUM(amount) FROM balance_entries WHERE company_id=" .
                            (int) $company,
                    )
                    ->fetchColumn() === "6.00",
            );
        }
        if (!$sms) {
            $cobs[$txid]["pix"][0]["devolucoes"] = [["status" => "DEVOLVIDO", "valor" => "2.00"]];
            $check($payments->reconcile($company, $id)["status"] === "partially_refunded");
            $check($payments->reconcile($company, $id)["reversed_amount"] === "2.00");
            $cobs[$txid]["pix"][0]["devolucoes"] = [];
            $check($payments->reconcile($company, $id)["reversed_amount"] === "2.00");
        }
        $cobs[$txid]["pix"][0]["devolucoes"] = [["status" => "DEVOLVIDO", "valor" => "6.00"]];
        $check($payments->reconcile($company, $id)["status"] === "refunded");
        $check($payments->reconcile($company, $id)["reversed_amount"] === "6.00");
        if ($sms) {
            $check(Pulse\SmsPurchases::available($db, $company) === 0);
        } else {
            $check(
                (string) $db
                    ->query(
                        "SELECT SUM(amount) FROM balance_entries WHERE company_id=" .
                            (int) $company,
                    )
                    ->fetchColumn() === "0.00",
            );
        }
        $remote = $gateway->fetchPayment($txid);
        $q = $db->prepare("SELECT * FROM payments WHERE provider_payment_id=?");
        $q->execute([$txid]);
        $payment = $q->fetch(PDO::FETCH_ASSOC);
        $remote["amount"] = "7.00";
        $reject(fn() => Pulse\PaymentValidator::validate($remote, $payment, "fixture-key"));
        $remote = $gateway->fetchPayment($txid);
        $remote["reference"] = str_repeat("0", 64);
        $reject(fn() => Pulse\PaymentValidator::validate($remote, $payment, "fixture-key"));
        $reject(
            fn() => Pulse\PaymentValidator::validate(
                $gateway->fetchPayment($txid),
                $payment,
                "other-merchant",
            ),
        );
    }
    $check(count(array_filter($calls, fn($call) => $call[0] === "PUT")) === 2);
    // O próprio SELECT do serviço exige a empresa correta.
    $q = $db->prepare(
        "SELECT provider_payment_id FROM payments WHERE company_id=? AND credit_request_id=?",
    );
    $q->execute(["0", $id]);
    $check(!$q->fetchColumn());
    $api = new Pulse\PanelApi($db, str_repeat("x", 64));
    $session = new ReflectionProperty($api, "session");
    $session->setAccessible(true);
    $session->setValue($api, ["company_id" => $company, "user_id" => $user, "role" => "viewer"]);
    $reject(fn() => $api->handle("pix_checkout", "POST", ["request_id" => $id]));
    $reject(fn() => $api->handle("pix_status", "POST", ["request_id" => $id]));
    $reject(fn() => $api->handle("pix_checkout", "GET", ["request_id" => $id]));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
echo "Efí Pix: $checks checks passed. Fake provider; database fixtures rolled back. No real payment.\n";

<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/PanelApi.php";
$config = require "C:/BLU/pulse-private/panel-local.php";
$db = new PDO($config["dsn"], $config["username"], $config["password"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($db->query("SELECT DATABASE()")->fetchColumn() !== "pulse_test") {
    throw new RuntimeException("Only pulse_test.");
}
$checks = 0;
$check = function (bool $ok) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException("Check " . ($checks + 1));
    }
    $checks++;
};
$db->exec(
    "INSERT INTO companies(name,status) VALUES('Temporary purchase dispatch check','active')",
);
$company = $db->lastInsertId();
$user = $db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
$api = new Pulse\PanelApi($db, str_repeat("x", 64));
$session = new ReflectionProperty($api, "session");
$session->setAccessible(true);
$session->setValue($api, ["company_id" => $company, "user_id" => $user, "role" => "owner"]);
try {
    $q = $db->prepare("INSERT INTO company_users(company_id,user_id,role) VALUES(?,?,'owner')");
    $q->execute([$company, $user]);
    foreach ([1, 50001] as $quantity) {
        $r = $api->handle("credit_requests", "POST", [
            "sms_quantity" => $quantity,
            "amount" => "0.01",
            "method" => "pix",
        ]);
        $check(Pulse\SmsPurchases::available($db, $company) === ($quantity === 1 ? 0 : 1));
        $q = $db->prepare("SELECT amount FROM credit_requests WHERE id=?");
        $q->execute([$r["id"]]);
        $check($q->fetchColumn() === ($quantity === 1 ? "0.06" : "2500.05"));
        $db->beginTransaction();
        Pulse\SmsPurchases::confirm($db, $company, $r["id"], "paid");
        $db->commit();
    }
    $check($api->handle("balance", "GET", [])["sms_available"] === 50002);
    $gateway = new Pulse\ChipeiraGateway(
        [
            "enabled" => true,
            "api_key" => str_repeat("x", 32),
            "base_url" => "http://127.0.0.1:3001",
            "allow_local_http" => true,
        ],
        function ($url, $verb) {
            return [
                "success" => true,
                "campaign" => [
                    "id" => 987655,
                    "status" => $verb === "POST" ? "running" : "completed",
                    "sent_count" => $verb === "POST" ? 0 : 1,
                ],
                "settlementReady" => true,
            ];
        },
    );
    $billing = new Pulse\SmsBilling($db, $gateway);
    $payload = [
        "request_id" => "purchase_dispatch_" . bin2hex(random_bytes(8)),
        "name" => "Fixture only",
        "message" => "Teste",
        "recipients" => ["+5511999990001", "+5511999990002"],
        "consent" => true,
        "expected_cents" => 11,
    ];
    $quote = $billing->estimate($company, $payload);
    $check(
        $quote["sms_credits"] && $quote["reserved_cents"] === 11 && $quote["balance_cents"] === 0,
    );
    $billing->create($company, $payload);
    $check(Pulse\SmsPurchases::available($db, $company) === 50000);
    $billing->create($company, $payload);
    $check(Pulse\SmsPurchases::available($db, $company) === 50000);
    $check($billing->reconcile($company)["settled"] === 1);
    $check(Pulse\SmsPurchases::available($db, $company) === 50001);
    $q = $db->prepare("SELECT charged_cents FROM sms_charges WHERE company_id=?");
    $q->execute([$company]);
    $check((int) $q->fetchColumn() === 6);
    $billing->reconcile($company);
    $check(Pulse\SmsPurchases::available($db, $company) === 50001);
    $check($api->handle("balance", "GET", [])["balance"] == 0);
    $long = $api->handle("credit_requests", "POST", [
        "sms_quantity" => 3,
        "sms_channel" => "longcode",
        "amount" => "0.01",
        "method" => "pix",
    ]);
    $db->beginTransaction();
    Pulse\SmsPurchases::confirm($db, $company, $long["id"], "paid");
    $db->commit();
    $longQuote = $billing->estimate($company, $payload + ["sms_channel" => "longcode"]);
    $check($longQuote["reserved_cents"] === 70 && $longQuote["sms_available"] === 3);
    $check(Pulse\SmsPurchases::available($db, $company) === 50001);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $q = $db->prepare(
        "DELETE a FROM sms_purchase_allocations a JOIN sms_charges c ON c.id=a.charge_id WHERE c.company_id=?",
    );
    $q->execute([$company]);
    foreach (["sms_charges", "sms_purchases", "credit_requests", "company_users"] as $table) {
        $q = $db->prepare("DELETE FROM $table WHERE company_id=?");
        $q->execute([$company]);
    }
    $q = $db->prepare("DELETE FROM companies WHERE id=?");
    $q->execute([$company]);
}
echo "Purchased SMS dispatch: $checks checks passed; fake gateway, no SMS sent.\n";

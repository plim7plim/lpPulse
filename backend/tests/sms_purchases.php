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
        throw new RuntimeException("Check " . ($checks + 1) . " failed");
    }
    $checks++;
};
$check(Pulse\SmsPurchases::quote(50000)["amount_cents"] === 300000);
$check(Pulse\SmsPurchases::quote(50001)["amount_cents"] === 250005);
$check(Pulse\SmsPurchases::quote(50000)["rate_mills"] === 60);
$check(Pulse\SmsPurchases::quote(50001)["rate_mills"] === 50);
foreach ([0, -1, 1.5, "50001", 1000001] as $bad) {
    try {
        Pulse\SmsPurchases::quote($bad);
        throw new RuntimeException("Expected invalid");
    } catch (Pulse\PanelError $e) {
        $check($e->errorCode === "invalid_quantity");
    }
}
$db->beginTransaction();
try {
    $db->exec(
        "INSERT INTO companies(name,status) VALUES('Temporary SMS purchase checks','active')",
    );
    $company = $db->lastInsertId();
    $user = $db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    $q = $db->prepare("INSERT INTO company_users(company_id,user_id,role) VALUES(?,?,'owner')");
    $q->execute([$company, $user]);
    $purchase = function (int $qty) use ($db, $company, $user) {
        $quote = Pulse\SmsPurchases::quote($qty);
        $q = $db->prepare(
            "INSERT INTO credit_requests(company_id,created_by,amount,method) VALUES(?,?,?,'pix')",
        );
        $q->execute([$company, $user, Pulse\PaymentValidator::decimal($quote["amount_cents"])]);
        $credit = $db->lastInsertId();
        $q = $db->prepare(
            "INSERT INTO sms_purchases(company_id,credit_request_id,quantity,rate_mills) VALUES(?,?,?,?)",
        );
        $q->execute([$company, $credit, $qty, $quote["rate_mills"]]);
        return $credit;
    };
    $a = $purchase(50000);
    $b = $purchase(50001);
    $check(Pulse\SmsPurchases::available($db, $company) === 0);
    $check(Pulse\SmsPurchases::confirm($db, $company, $a, "paid"));
    $check(Pulse\SmsPurchases::confirm($db, $company, $a, "paid"));
    $check(Pulse\SmsPurchases::available($db, $company) === 50000);
    Pulse\SmsPurchases::confirm($db, $company, $b, "paid");
    $plan = Pulse\SmsPurchases::plan($db, $company, 50002);
    $check($plan["cost"] === 300010);
    $q = $db->prepare(
        "INSERT INTO sms_charges(company_id,request_id,payload_hash,payload,rate_mills,segments,recipients,reserved_cents) VALUES(?,?,?,'{}',60,1,2,?)",
    );
    $q->execute([$company, "fixture_purchase_plan", str_repeat("a", 64), $plan["cost"]]);
    $charge = $db->lastInsertId();
    Pulse\SmsPurchases::reserve($db, $charge, $plan);
    $check(Pulse\SmsPurchases::available($db, $company) === 49999);
    $check(Pulse\SmsPurchases::settle($db, $charge, 50001) === 300005);
    $check(Pulse\SmsPurchases::available($db, $company) === 50000);
    $check(Pulse\SmsPurchases::settle($db, $charge, 50001) === 300005);
    $check(Pulse\SmsPurchases::available($db, $company) === 50000);
    $check(Pulse\SmsPurchases::plan($db, $company, 1)["cost"] === 5);
    $check(
        (int) $db
            ->query("SELECT COUNT(*) FROM balance_entries WHERE company_id=" . $company)
            ->fetchColumn() === 0,
    );
    $c = $purchase(5);
    Pulse\SmsPurchases::confirm($db, $company, $c, "cancelled");
    $check(Pulse\SmsPurchases::available($db, $company) === 50000);
} finally {
    $db->rollBack();
}
echo "SMS purchases: $checks checks passed; no real SMS or payments.\n";

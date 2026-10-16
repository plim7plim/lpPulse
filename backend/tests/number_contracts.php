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
$db->exec("SET time_zone='+00:00'");
if (
    $db
        ->query(
            "SELECT COUNT(*) FROM number_inventory WHERE phone LIKE '+5599%' AND status='available'",
        )
        ->fetchColumn() != 0
) {
    throw new RuntimeException("Fixture DDD occupied.");
}
$checks = 0;
$check = function (bool $ok) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException("Check " . ($checks + 1));
    }
    $checks++;
};
$reject = function (callable $f, string $code) use ($check) {
    try {
        $f();
    } catch (Pulse\PanelError $e) {
        $check($e->errorCode === $code);
        return;
    }
    throw new RuntimeException("Expected " . $code);
};
$db->exec("INSERT INTO companies(name,status) VALUES('Temporary contract checks','active')");
$company = $db->lastInsertId();
$user = (string) $db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
$q = function (string $sql, array $args = []) use ($db) {
    $q = $db->prepare($sql);
    $q->execute($args);
    return $q;
};
$q("INSERT INTO company_users(company_id,user_id,role) VALUES(?,?,'owner')", [$company, $user]);
$q(
    "INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'credit','1000.00','Fixture',UTC_TIMESTAMP())",
    [$company, hash("sha256", "fixture-contract:" . $company)],
);
$gateway = new Pulse\ChipeiraGateway(
    [
        "enabled" => true,
        "api_key" => str_repeat("x", 32),
        "base_url" => "http://127.0.0.1:3001",
        "allow_local_http" => true,
    ],
    fn($url, $verb, $body) => [
        "success" => true,
        "delivered" => true,
        "phoneNumber" => $body["number"] ?? "",
        "messages" => [],
    ],
);
$contracts = new Pulse\NumberContracts($db, $gateway);
$delivery = new Pulse\NumberDelivery($db, $gateway);
$stock = [];
$balance = fn() => $q("SELECT SUM(amount) FROM balance_entries WHERE company_id=?", [
    $company,
])->fetchColumn();
try {
    $buy = [
        "product" => "online",
        "request_id" => "fixture_online_" . bin2hex(random_bytes(8)),
        "confirm" => true,
        "amount_cents" => 1499,
    ];
    $contracts->buy($company, $buy);
    $rows = $contracts->listing($company)["items"];
    $id = $rows[0]["id"];
    $check($balance() === "985.01");
    $contracts->buy($company, $buy);
    $check($balance() === "985.01");
    $reject(
        fn() => $contracts->buy($company, [
            "product" => "online",
            "request_id" => "invalid_price_" . bin2hex(random_bytes(8)),
            "confirm" => true,
            "amount_cents" => 1,
        ]),
        "price_changed",
    );
    for ($i = 0; $i < 2; $i++) {
        $phone = "+5599" . str_pad((string) random_int(100000000, 999999999), 9, "0", STR_PAD_LEFT);
        $q(
            "INSERT INTO number_inventory(phone,slot,type,verification_note) VALUES(?,1,'sms','Fixture')",
            [$phone],
        );
        $stock[] = $db->lastInsertId();
    }
    $request = [
        "id" => $id,
        "quantity" => 1,
        "ddd" => "99",
        "request_id" => "fixture_redeem_" . bin2hex(random_bytes(8)),
    ];
    $contracts->redeem($company, $user, $request);
    $contracts->redeem($company, $user, $request);
    $check($balance() === "985.01");
    $check(count($delivery->owned($company)) === 0);
    $delivery->deliver($company);
    $check(count($delivery->owned($company)) === 1);
    $check($contracts->listing($company)["items"][0]["status"] === "active");
    $contracts->redeem($company, $user, [
        "id" => $id,
        "quantity" => 1,
        "ddd" => "99",
        "request_id" => "fixture_swap_" . bin2hex(random_bytes(8)),
    ]);
    $delivery->deliver($company);
    $check(count($delivery->owned($company)) === 1);
    $firstOrder = $q(
        "SELECT id FROM number_contract_orders WHERE contract_id=? ORDER BY id LIMIT 1",
        [$id],
    )->fetchColumn();
    $q("UPDATE number_contract_orders SET quantity=10 WHERE id=?", [$firstOrder]);
    $reject(
        fn() => $contracts->redeem($company, $user, [
            "id" => $id,
            "quantity" => 1,
            "ddd" => "99",
            "request_id" => "quota_" . bin2hex(random_bytes(8)),
        ]),
        "quota_exceeded",
    );
    $q("UPDATE number_contract_orders SET quantity=1 WHERE id=?", [$firstOrder]);
    $check(
        (int) $q("SELECT COUNT(*) FROM number_inventory WHERE company_id=? AND status='retired'", [
            $company,
        ])->fetchColumn() === 1,
    );
    $q(
        "UPDATE number_contracts SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?",
        [$id],
    );
    $check(count($delivery->owned($company)) === 0);
    $check($contracts->listing($company)["items"][0]["status"] === "expired");
    $api = new Pulse\PanelApi($db, str_repeat("x", 64));
    $session = new ReflectionProperty($api, "session");
    $session->setAccessible(true);
    $session->setValue($api, ["company_id" => $company, "user_id" => $user, "role" => "owner"]);
    $oldPhone = $q(
        "SELECT phone FROM number_inventory WHERE company_id=? AND status='retired' LIMIT 1",
        [$company],
    )->fetchColumn();
    foreach ([ltrim($oldPhone, "+"), substr($oldPhone, 3)] as $variant) {
        $reject(
            fn() => $api->handle("chipeira", "GET", ["op" => "messages", "number" => $variant]),
            "contract_expired",
        );
    }

    $reject(
        fn() => $contracts->redeem($company, $user, [
            "id" => $id,
            "quantity" => 1,
            "ddd" => "99",
            "request_id" => "fixture_expired_" . bin2hex(random_bytes(8)),
        ]),
        "contract_inactive",
    );
    $renew = [
        "id" => $id,
        "request_id" => "fixture_renew_" . bin2hex(random_bytes(8)),
        "confirm" => true,
        "amount_cents" => 1499,
    ];
    $contracts->renew($company, $renew);
    $contracts->renew($company, $renew);
    $check($balance() === "970.02");
    $pending = $contracts->buy($company, [
        "product" => "online",
        "request_id" => "cancel_" . bin2hex(random_bytes(8)),
        "confirm" => true,
        "amount_cents" => 1499,
    ]);
    $contracts->cancelPending($company, $user, [
        "id" => $pending["id"],
        "note" => "Fixture cancellation",
    ]);
    $contracts->cancelPending($company, $user, [
        "id" => $pending["id"],
        "note" => "Fixture replay",
    ]);
    $check($balance() === "970.02");
    $sub = $contracts->buy($company, [
        "product" => "subscription",
        "request_id" => "fixture_sub_" . bin2hex(random_bytes(8)),
        "confirm" => true,
        "amount_cents" => 59999,
    ]);
    $check($balance() === "370.03");
    $reject(
        fn() => $contracts->redeem($company, $user, [
            "id" => $sub["id"],
            "quantity" => 100,
            "ddd" => "99",
            "request_id" => "fixture_empty_" . bin2hex(random_bytes(8)),
        ]),
        "insufficient_stock",
    );
    $row = $contracts->listing($company)["items"][0];
    $check((int) $row["used"] === 0);
    $check(Pulse\SmsPurchases::quote(50001, "longcode")["amount_cents"] === 1750035);
    $check(Pulse\SmsPurchases::quote(50001, "longcode")["rate_mills"] === 350);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $q(
        "DELETE o FROM number_contract_orders o JOIN number_contracts c ON c.id=o.contract_id WHERE c.company_id=?",
        [$company],
    );
    $q(
        "DELETE r FROM number_contract_renewals r JOIN number_contracts c ON c.id=r.contract_id WHERE c.company_id=?",
        [$company],
    );
    $q("DELETE FROM admin_events WHERE action='contract_cancel' AND details LIKE ?", [
        '%"company_id":"' . $company . '"%',
    ]);
    foreach ($stock as $stockId) {
        $q("DELETE FROM number_inventory WHERE id=?", [$stockId]);
    }
    foreach (
        [
            "number_contracts",
            "number_fulfillments",
            "service_requests",
            "balance_entries",
            "company_users",
        ]
        as $table
    ) {
        $q("DELETE FROM $table WHERE company_id=?", [$company]);
    }
    $q("DELETE FROM companies WHERE id=?", [$company]);
}
echo "Number contracts: $checks checks passed. Fake gateway only; no real purchase or hardware access.\n";

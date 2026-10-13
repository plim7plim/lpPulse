<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/PanelApi.php";
$config = require "C:/BLU/pulse-private/panel-local.php";
$db = new PDO($config["dsn"], $config["username"], $config["password"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($db->query("SELECT DATABASE()")->fetchColumn() !== "pulse_test") {
    throw new RuntimeException("Only pulse_test");
}
$fixture = bin2hex(random_bytes(8));
$companies = [];
$inventory = [];
$keys = [];
$checks = 0;
$mode = "success";
$remoteCalls = 0;
$q = function ($sql, $args = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($args);
    return $s;
};
$check = function ($ok) use (&$checks) {
    if (!$ok) {
        throw new RuntimeException("Delivery check " . ($checks + 1));
    }
    $checks++;
};
$reject = function ($fn, $code) use ($check) {
    try {
        $fn();
    } catch (Pulse\PanelError $e) {
        $check($e->errorCode === $code);
        return;
    }
    throw new RuntimeException("Expected " . $code);
};
$gateway = new Pulse\ChipeiraGateway(
    [
        "enabled" => true,
        "api_key" => str_repeat("x", 32),
        "base_url" => "http://127.0.0.1:3001",
        "allow_local_http" => true,
    ],
    function ($url, $verb, $body) use (&$mode, &$remoteCalls) {
        $remoteCalls++;
        if ($mode === "timeout") {
            throw new Pulse\PanelError("chipeira_offline", "Timeout", 503);
        }
        if ($mode === "reject") {
            throw new Pulse\PanelError("chipeira_error", "Occupied", 409);
        }
        if ($verb === "GET") {
            return [
                "success" => true,
                "messages" => [
                    [
                        "id" => 1,
                        "phone_number" => "WhatsApp",
                        "body" => "Código de teste 123-456",
                        "created_at" => "2026-10-13 12:00:00",
                    ],
                ],
            ];
        }
        return ["success" => true, "delivered" => true, "phoneNumber" => $body["number"]];
    },
);
$delivery = new Pulse\NumberDelivery($db, $gateway);
$stock = function ($phone, $type = "sms") use ($delivery, &$inventory) {
    $r = $delivery->stock(
        [
            "op" => "stock_add",
            "phone" => $phone,
            "slot" => 1,
            "type" => $type,
            "verified" => true,
            "note" => "Temporary fixture",
        ],
        "101",
    );
    $inventory[] = $r["id"];
    return $r["id"];
};
$order = function ($company, $quantity = 1, $type = "sms") use ($q, $db, $fixture) {
    $q(
        "INSERT INTO service_requests(company_id,created_by,service,details,status) VALUES(?,101,?,?,'submitted')",
        [
            $company,
            $type === "sms" ? "sms_number" : "whatsapp_number",
            json_encode([
                "request_id" => $fixture,
                "quantity" => $quantity,
                "ddd" => "11",
                "type" => $type,
                "notes" => "Fixture",
            ]),
        ],
    );
    return $db->lastInsertId();
};
try {
    foreach ([1, 2] as $i) {
        $q("INSERT INTO companies(name,status) VALUES(?,'active')", [
            "Delivery fixture " . $fixture . " " . $i,
        ]);
        $company = $db->lastInsertId();
        $companies[] = $company;
        $q(
            "INSERT INTO company_users(company_id,user_id,role,status) VALUES(?,101,'owner','active')",
            [$company],
        );
        $key = hash("sha256", $fixture . $i);
        $keys[] = $key;
        $q(
            "INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'credit','100.00','Fixture',UTC_TIMESTAMP())",
            [$company, $key],
        );
    }
    [$first, $second] = $companies;
    $phone = "+5511" . substr((string) hexdec(substr($fixture, 0, 8)) . "000000000", 0, 9);
    $stock($phone);
    $reject(fn() => $stock($phone), "stock_exists");
    $id = $order($first);
    $delivery->quote($first, ["id" => $id, "amount" => "20.00", "note" => "One SMS number"], "101");
    $quote = $delivery->quotes($first)[0];
    $check((int) $quote["amount_cents"] === 2000);
    $check(!$delivery->owned($first));
    $check(!$delivery->quotes($second));
    $payload = [
        "id" => $id,
        "version" => (int) $quote["version"],
        "amount_cents" => 2000,
        "confirm" => true,
    ];
    $reject(fn() => $delivery->pay($second, $payload), "not_found");
    $bad = $payload;
    $bad["amount_cents"] = 1;
    $reject(fn() => $delivery->pay($first, $bad), "quote_changed");
    $delivery->pay($first, $payload);
    $delivery->pay($first, $payload);
    $check(
        $q("SELECT SUM(amount) FROM balance_entries WHERE company_id=?", [
            $first,
        ])->fetchColumn() === "80.00",
    );
    $check(!$delivery->owned($first));
    $other = $order($second);
    $delivery->quote(
        $second,
        ["id" => $other, "amount" => "10.00", "note" => "Other buyer"],
        "101",
    );
    $reject(
        fn() => $delivery->pay($second, [
            "id" => $other,
            "version" => 1,
            "amount_cents" => 1000,
            "confirm" => true,
        ]),
        "insufficient_stock",
    );
    $mode = "timeout";
    $check($delivery->deliver($first)["pending"] === 1);
    $check(!$delivery->owned($first));
    $check(
        $q("SELECT SUM(amount) FROM balance_entries WHERE company_id=?", [
            $first,
        ])->fetchColumn() === "80.00",
    );
    $mode = "success";
    $check($delivery->deliver($first)["delivered"] === 1);
    $owned = $delivery->owned($first);
    $check(count($owned) === 1 && $owned[0]["phone"] === $phone);
    $check(!$delivery->owned($second));
    $reject(fn() => $delivery->messages($second, ["id" => $owned[0]["id"]]), "not_found");
    $check(
        $delivery->messages($first, ["id" => $owned[0]["id"]])["messages"][0]["body"] ===
            "Código de teste 123-456",
    );
    $calls = $remoteCalls;
    $delivery->deliver($first);
    $check($calls === $remoteCalls);
    $phone2 = substr($phone, 0, -1) . ($phone[strlen($phone) - 1] === "9" ? "8" : "9");
    $stock($phone2);
    $failureOrder = $order($first);
    $delivery->quote(
        $first,
        ["id" => $failureOrder, "amount" => "13.57", "note" => "Failure fixture"],
        "101",
    );
    $delivery->pay($first, [
        "id" => $failureOrder,
        "version" => 1,
        "amount_cents" => 1357,
        "confirm" => true,
    ]);
    $mode = "reject";
    $check($delivery->deliver($first)["failed"] === 1);
    $delivery->deliver($first);
    $check(
        $q("SELECT SUM(amount) FROM balance_entries WHERE company_id=?", [
            $first,
        ])->fetchColumn() === "80.00",
    );
    $api = new Pulse\PanelApi($db, $config["api_secret"]);
    $reflection = new ReflectionProperty($api, "session");
    $reflection->setValue($api, ["user_id" => "101", "company_id" => $first, "role" => "owner"]);
    $reject(fn() => $api->handle("admin", "GET", []), "forbidden");
    $reject(fn() => $api->handle("chipeira", "POST", ["op" => "deliver_number"]), "forbidden");
    $reflection->setValue($api, ["user_id" => "101", "company_id" => $first, "role" => "operator"]);
    $reject(fn() => $api->handle("purchased_numbers", "POST", ["op" => "pay"]), "forbidden");
    $reject(
        fn() => $delivery->stock(
            [
                "op" => "stock_add",
                "phone" => "+5511999990000",
                "slot" => 2,
                "type" => "whatsapp",
                "verified" => false,
                "note" => "Fixture",
            ],
            "101",
        ),
        "verification_required",
    );
    echo "Number delivery: $checks checks passed. No network, SMS, stock reservation or real purchase.\n";
} finally {
    foreach ($inventory as $id) {
        $q("DELETE FROM number_inventory WHERE id=?", [$id]);
    }
    foreach ($companies as $company) {
        foreach (
            ["number_fulfillments", "balance_entries", "service_requests", "company_users"]
            as $table
        ) {
            $q("DELETE FROM " . $table . " WHERE company_id=?", [$company]);
        }
        $q("DELETE FROM companies WHERE id=?", [$company]);
        $q("DELETE FROM admin_events WHERE details LIKE ?", ['%"company_id":"' . $company . '"%']);
    }
    foreach ($inventory as $id) {
        $q(
            "DELETE FROM admin_events WHERE action IN ('stock_add','stock_retire') AND details LIKE ?",
            ['%"id":"' . $id . '"%'],
        );
    }
}

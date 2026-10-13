<?php
declare(strict_types=1);
require_once __DIR__ . "/../src/NumberDelivery.php";
$config = require "C:/BLU/pulse-private/panel-local.php";
$db = new PDO($config["dsn"], $config["username"], $config["password"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($db->query("SELECT DATABASE()")->fetchColumn() !== "pulse_test") {
    throw new RuntimeException("Only pulse_test");
}
$delivery = new Pulse\NumberDelivery($db, new Pulse\ChipeiraGateway([]));
if (($argv[1] ?? "") === "worker") {
    while (microtime(true) < (float) $argv[4]) {
        usleep(10000);
    }
    try {
        $delivery->pay($argv[2], [
            "id" => $argv[3],
            "version" => 1,
            "amount_cents" => 100,
            "confirm" => true,
        ]);
        echo "paid";
    } catch (Pulse\PanelError $e) {
        echo $e->errorCode;
    }
    exit();
}
$companies = [];
$inventory = null;
$processes = [];
$fixture = bin2hex(random_bytes(8));
$q = function ($sql, $args = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($args);
    return $s;
};
try {
    $phone = "+5511" . substr((string) hexdec(substr($fixture, 0, 8)) . "999999999", 0, 9);
    $inventory = $delivery->stock(
        ["op" => "stock_add", "phone" => $phone, "slot" => 2, "type" => "sms", "note" => $fixture],
        "101",
    )["id"];
    $orders = [];
    foreach ([1, 2] as $index) {
        $q("INSERT INTO companies(name,status) VALUES(?,'active')", ["Concurrency " . $fixture]);
        $company = $db->lastInsertId();
        $companies[] = $company;
        $q(
            "INSERT INTO company_users(company_id,user_id,role,status) VALUES(?,101,'owner','active')",
            [$company],
        );
        $q(
            "INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'credit','1.00','Fixture',UTC_TIMESTAMP())",
            [$company, hash("sha256", $fixture . $index)],
        );
        $q(
            "INSERT INTO service_requests(company_id,created_by,service,details,status) VALUES(?,101,'sms_number',?,'submitted')",
            [$company, json_encode(["quantity" => 1, "ddd" => "11", "type" => "sms"])],
        );
        $id = $db->lastInsertId();
        $orders[] = $id;
        $delivery->quote($company, ["id" => $id, "amount" => "1.00", "note" => $fixture], "101");
    }
    $start = (string) (microtime(true) + 1);
    foreach ($companies as $index => $company) {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                "-c",
                "C:/BLU/pulse-local/php.ini",
                __FILE__,
                "worker",
                $company,
                $orders[$index],
                $start,
            ],
            [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot launch verification");
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException("Worker failed");
        }
    }
    $processes = [];
    sort($results);
    if ($results !== ["insufficient_stock", "paid"]) {
        throw new RuntimeException("Unexpected race result " . json_encode($results));
    }
    $rows = $q("SELECT status,company_id FROM number_inventory WHERE id=?", [$inventory])->fetchAll(
        PDO::FETCH_ASSOC,
    );
    if (count($rows) !== 1 || $rows[0]["status"] !== "allocated") {
        throw new RuntimeException("Duplicate allocation");
    }
    foreach ($companies as $company) {
        $balance = $q("SELECT SUM(amount) FROM balance_entries WHERE company_id=?", [
            $company,
        ])->fetchColumn();
        if (!in_array($balance, ["0.00", "1.00"], true)) {
            throw new RuntimeException("Incorrect balance");
        }
    }
    echo "Number concurrency: two buyers, one number, one debit. No remote requests.\n";
} finally {
    foreach ($processes as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    if ($inventory) {
        $q("DELETE FROM number_inventory WHERE id=?", [$inventory]);
    }
    foreach ($companies as $company) {
        foreach (
            ["number_fulfillments", "balance_entries", "service_requests", "company_users"]
            as $table
        ) {
            $q("DELETE FROM " . $table . " WHERE company_id=?", [$company]);
        }
        $q("DELETE FROM companies WHERE id=?", [$company]);
    }
    $q("DELETE FROM admin_events WHERE details LIKE ?", ["%" . $fixture . "%"]);
}

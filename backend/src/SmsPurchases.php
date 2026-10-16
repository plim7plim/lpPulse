<?php
declare(strict_types=1);
namespace Pulse;

final class SmsPurchases
{
    public static function quote($quantity, $channel = "sms"): array
    {
        if (!is_int($quantity) || $quantity < 1 || $quantity > 1000000) {
            throw new PanelError("invalid_quantity", "Informe entre 1 e 1.000.000 SMS inteiros.");
        }
        $catalog = json_decode(
            file_get_contents(__DIR__ . "/../../assets/tarifas.json"),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        if (!in_array($channel, ["sms", "longcode"], true)) {
            throw new PanelError("invalid_channel", "Modalidade de SMS inválida.");
        }
        if ($channel === "longcode") {
            $rate = (int) $catalog["longcode_rate_mills"];
            return [
                "channel" => $channel,
                "quantity" => $quantity,
                "rate_mills" => $rate,
                "amount_cents" => intdiv($quantity * $rate + 9, 10),
            ];
        }
        foreach ($catalog["sms"] as $band) {
            if ($band["max"] === null || $quantity <= $band["max"]) {
                $rate = (int) $band["rate_mills"];
                return [
                    "channel" => $channel,
                    "quantity" => $quantity,
                    "rate_mills" => $rate,
                    "amount_cents" => intdiv($quantity * $rate + 9, 10),
                ];
            }
        }
        throw new \RuntimeException("Tabela de SMS inválida.");
    }
    private static function q(\PDO $db, string $sql, array $args): \PDOStatement
    {
        $q = $db->prepare($sql);
        $q->execute($args);
        return $q;
    }
    public static function confirm(\PDO $db, string $company, string $credit, string $status): bool
    {
        $row = self::q(
            $db,
            "SELECT * FROM sms_purchases WHERE company_id=? AND credit_request_id=? FOR UPDATE",
            [$company, $credit],
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($row["status"] === "pending") {
            self::q($db, "UPDATE sms_purchases SET status=?,remaining=? WHERE id=?", [
                $status,
                $status === "paid" ? (int) $row["quantity"] : 0,
                $row["id"],
            ]);
        }
        return true;
    }
    public static function available(\PDO $db, string $company, string $channel = "sms"): int
    {
        return (int) self::q(
            $db,
            "SELECT COALESCE(SUM(remaining),0) FROM sms_purchases WHERE company_id=? AND status='paid' AND channel=?",
            [$company, $channel],
        )->fetchColumn();
    }
    public static function plan(
        \PDO $db,
        string $company,
        int $units,
        string $channel = "sms"
    ): ?array {
        $rows = self::q(
            $db,
            "SELECT id,remaining,rate_mills FROM sms_purchases WHERE company_id=? AND status='paid' AND channel=? AND remaining>0 ORDER BY id",
            [$company, $channel],
        )->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        $plan = [];
        $cost = 0;
        $left = $units;
        foreach ($rows as $row) {
            $take = min($left, (int) $row["remaining"]);
            $plan[] = [
                "id" => $row["id"],
                "units" => $take,
                "rate_mills" => (int) $row["rate_mills"],
            ];
            $cost += intdiv($take * (int) $row["rate_mills"] + 9, 10);
            $left -= $take;
            if (!$left) {
                break;
            }
        }
        if ($left) {
            throw new PanelError(
                "insufficient_sms",
                "Créditos SMS insuficientes. Compre mais no Financeiro.",
                409,
            );
        }
        return ["items" => $plan, "cost" => $cost];
    }
    public static function reserve(\PDO $db, string $charge, array $plan): void
    {
        foreach ($plan["items"] as $item) {
            $updated = self::q(
                $db,
                "UPDATE sms_purchases SET remaining=remaining-? WHERE id=? AND remaining>=?",
                [$item["units"], $item["id"], $item["units"]],
            );
            if ($updated->rowCount() !== 1) {
                throw new PanelError(
                    "insufficient_sms",
                    "Os créditos mudaram. Revise o envio novamente.",
                    409,
                );
            }
            self::q(
                $db,
                "INSERT INTO sms_purchase_allocations(charge_id,purchase_id,units) VALUES(?,?,?)",
                [$charge, $item["id"], $item["units"]],
            );
        }
    }
    public static function settle(\PDO $db, string $charge, int $sentUnits): ?int
    {
        $rows = self::q(
            $db,
            "SELECT a.*,p.rate_mills FROM sms_purchase_allocations a JOIN sms_purchases p ON p.id=a.purchase_id WHERE a.charge_id=? ORDER BY p.id FOR UPDATE",
            [$charge],
        )->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        $left = $sentUnits;
        $cost = 0;
        foreach ($rows as $row) {
            $used = min($left, (int) $row["units"]);
            $left -= $used;
            $cost += intdiv($used * (int) $row["rate_mills"] + 9, 10);
            if ($row["charged_units"] !== null) {
                continue;
            }
            self::q($db, "UPDATE sms_purchases SET remaining=remaining+? WHERE id=?", [
                (int) $row["units"] - $used,
                $row["purchase_id"],
            ]);
            self::q(
                $db,
                "UPDATE sms_purchase_allocations SET charged_units=? WHERE charge_id=? AND purchase_id=?",
                [$used, $charge, $row["purchase_id"]],
            );
        }
        if ($left) {
            throw new \RuntimeException("Contagem de SMS divergente.");
        }
        return $cost;
    }
}

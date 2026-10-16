<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__ . "/NumberDelivery.php";

final class NumberContracts
{
    private \PDO $db;
    private NumberDelivery $delivery;
    public function __construct(\PDO $db, ChipeiraGateway $gateway)
    {
        $this->db = $db;
        $this->delivery = new NumberDelivery($db, $gateway);
    }
    private function q(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q;
    }
    private function key(array $data): string
    {
        $key = PanelValidation::text($data, "request_id", 80);
        if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $key)) {
            throw new PanelError("invalid_request", "Atualize o formulário e tente novamente.");
        }
        return $key;
    }
    private function cost(string $product): int
    {
        $catalog = json_decode(
            file_get_contents(__DIR__ . "/../../assets/tarifas.json"),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        foreach ($catalog["numbers"] as $item) {
            if ($item["id"] === $product) {
                return (int) $item["price_cents"];
            }
        }
        throw new PanelError("invalid_product", "Produto indisponível.");
    }
    private function debit(string $company, int $amount, string $key, string $description): void
    {
        $balance = (string) $this->q(
            "SELECT COALESCE(SUM(amount),0) FROM balance_entries WHERE company_id=?",
            [$company],
        )->fetchColumn();
        if (strpos($balance, "-") === 0 || PaymentValidator::cents($balance) < $amount) {
            throw new PanelError(
                "insufficient_balance",
                "Saldo em reais insuficiente. Solicite uma recarga no Financeiro.",
                409,
            );
        }
        $this->q(
            "INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'debit',?,?,UTC_TIMESTAMP())",
            [
                $company,
                hash("sha256", $key),
                "-" . PaymentValidator::decimal($amount),
                $description,
            ],
        );
    }
    public function expire(): void
    {
        $this->q(
            "UPDATE number_contracts SET status='expired' WHERE status='active' AND expires_at<=UTC_TIMESTAMP()",
        );
        $this->q(
            "UPDATE number_inventory i JOIN number_fulfillments f ON f.id=i.fulfillment_id JOIN number_contract_orders o ON o.service_request_id=f.service_request_id JOIN number_contracts c ON c.id=o.contract_id SET i.status='retired',i.last_error='Contrato vencido; conferir desvínculo na chipeira antes de reutilizar.' WHERE c.product='online' AND c.status IN ('expired','cancelled') AND i.status='delivered'",
        );
    }
    public function sync(string $company): void
    {
        $this->expire();
        $this->q(
            "UPDATE number_contract_orders o JOIN number_fulfillments f ON f.service_request_id=o.service_request_id SET o.status='failed' WHERE f.company_id=? AND o.status='reserved' AND f.status='completed' AND NOT EXISTS(SELECT 1 FROM number_inventory i WHERE i.fulfillment_id=f.id AND i.status IN ('allocated','activating','delivered','retired'))",
            [$company],
        );
        $this->q(
            "UPDATE number_contracts c SET c.status='active',c.started_at=UTC_TIMESTAMP(),c.expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY) WHERE c.company_id=? AND c.product='online' AND c.status='paid' AND EXISTS(SELECT 1 FROM number_contract_orders o JOIN number_fulfillments f ON f.service_request_id=o.service_request_id JOIN number_inventory i ON i.fulfillment_id=f.id WHERE o.contract_id=c.id AND i.status='delivered')",
            [$company],
        );
        $this->q(
            "UPDATE number_inventory old JOIN number_fulfillments oful ON oful.id=old.fulfillment_id JOIN number_contract_orders oo ON oo.service_request_id=oful.service_request_id JOIN number_contracts c ON c.id=oo.contract_id SET old.status='retired',old.last_error='Número substituído; conferir desvínculo na chipeira antes de reutilizar.' WHERE c.company_id=? AND c.product='online' AND old.status='delivered' AND EXISTS(SELECT 1 FROM number_contract_orders newer JOIN number_fulfillments nf ON nf.service_request_id=newer.service_request_id JOIN number_inventory ni ON ni.fulfillment_id=nf.id WHERE newer.contract_id=c.id AND newer.id>oo.id AND ni.status='delivered')",
            [$company],
        );
    }
    public function listing(string $company): array
    {
        $this->sync($company);
        $items = $this->q(
            "SELECT c.*,COALESCE((SELECT SUM(GREATEST(0,o.quantity-(SELECT COUNT(*) FROM number_fulfillments ff JOIN number_inventory ii ON ii.fulfillment_id=ff.id WHERE ff.service_request_id=o.service_request_id AND ii.status='failed'))) FROM number_contract_orders o WHERE o.contract_id=c.id AND o.cycle=c.cycle AND o.status<>'failed'),0) used,(SELECT COUNT(*) FROM number_contract_orders o JOIN number_fulfillments f ON f.service_request_id=o.service_request_id JOIN number_inventory i ON i.fulfillment_id=f.id WHERE o.contract_id=c.id AND o.cycle=c.cycle AND i.status='delivered') delivered FROM number_contracts c WHERE c.company_id=? ORDER BY c.id DESC LIMIT 100",
            [$company],
        )->fetchAll(\PDO::FETCH_ASSOC);
        return ["items" => $items];
    }
    public function buy(string $company, array $data): array
    {
        $product = PanelValidation::choice($data, "product", ["online", "subscription"]);
        $key = $this->key($data);
        $amount = $this->cost($product);
        if (($data["confirm"] ?? false) !== true || ($data["amount_cents"] ?? null) !== $amount) {
            throw new PanelError("price_changed", "Revise o preço antes de confirmar.", 409);
        }
        $this->db->beginTransaction();
        try {
            $this->q("SELECT id FROM companies WHERE id=? FOR UPDATE", [$company]);
            $old = $this->q("SELECT * FROM number_contracts WHERE company_id=? AND request_key=?", [
                $company,
                $key,
            ])->fetch(\PDO::FETCH_ASSOC);
            if ($old) {
                if ($old["product"] !== $product) {
                    throw new PanelError("request_conflict", "Pedido já utilizado.", 409);
                }
                $id = $old["id"];
            } else {
                $this->debit(
                    $company,
                    $amount,
                    "contract:" . $company . ":" . $key,
                    "Contratação · " . $product . " · 30 dias",
                );
                $this->q(
                    "INSERT INTO number_contracts(company_id,product,request_key,price_cents,status,started_at,expires_at) VALUES(?,?,?,?,?,IF(?='subscription',UTC_TIMESTAMP(),NULL),IF(?='subscription',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),NULL))",
                    [
                        $company,
                        $product,
                        $key,
                        $amount,
                        $product === "subscription" ? "active" : "paid",
                        $product,
                        $product,
                    ],
                );
                $id = $this->db->lastInsertId();
            }
            $this->db->commit();
            return ["id" => $id, "paid" => true, "replayed" => (bool) $old];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function renew(string $company, array $data): array
    {
        $id = PanelValidation::id($data["id"] ?? null);
        $key = $this->key($data);
        $this->sync($company);
        $this->db->beginTransaction();
        try {
            $this->q("SELECT id FROM companies WHERE id=? FOR UPDATE", [$company]);
            $c = $this->q("SELECT * FROM number_contracts WHERE id=? AND company_id=? FOR UPDATE", [
                $id,
                $company,
            ])->fetch(\PDO::FETCH_ASSOC);
            if (!$c) {
                throw new PanelError("not_found", "Contrato não encontrado.", 404);
            }
            if (
                $this->q(
                    "SELECT 1 FROM number_contract_renewals WHERE contract_id=? AND request_key=?",
                    [$id, $key],
                )->fetchColumn()
            ) {
                $this->db->commit();
                return ["renewed" => true, "replayed" => true];
            }
            if (
                !in_array($c["status"], ["active", "expired"], true) ||
                ($c["status"] === "active" && strtotime($c["expires_at"] . " UTC") > time() + 86400)
            ) {
                throw new PanelError(
                    "renewal_early",
                    "Renove a partir das últimas 24 horas do período.",
                    409,
                );
            }
            $amount = $this->cost($c["product"]);
            if (
                ($data["amount_cents"] ?? null) !== $amount ||
                ($data["confirm"] ?? false) !== true
            ) {
                throw new PanelError("price_changed", "Revise o valor da renovação.", 409);
            }
            $this->debit(
                $company,
                $amount,
                "renew:" . $id . ":" . $key,
                "Renovação contrato #" . $id,
            );
            $this->q("INSERT INTO number_contract_renewals(contract_id,request_key) VALUES(?,?)", [
                $id,
                $key,
            ]);
            $this->q(
                "UPDATE number_contracts SET cycle=cycle+1,status=?,price_cents=?,started_at=UTC_TIMESTAMP(),expires_at=IF(?='paid',NULL,DATE_ADD(GREATEST(COALESCE(expires_at,UTC_TIMESTAMP()),UTC_TIMESTAMP()),INTERVAL 30 DAY)) WHERE id=?",
                [
                    $c["product"] === "online" && $c["status"] === "expired" ? "paid" : "active",
                    $amount,
                    $c["product"] === "online" && $c["status"] === "expired" ? "paid" : "active",
                    $id,
                ],
            );
            $this->db->commit();
            return ["renewed" => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function redeem(string $company, string $user, array $data): array
    {
        $id = PanelValidation::id($data["id"] ?? null);
        $key = $this->key($data);
        $quantity = $data["quantity"] ?? 1;
        $ddd = $data["ddd"] ?? "";
        if (!is_string($ddd) || ($ddd !== "" && !preg_match("/^[1-9][0-9]$/D", $ddd))) {
            throw new PanelError("invalid_ddd", "Informe um DDD de dois dígitos.");
        }
        if (!is_int($quantity) || $quantity < 1 || $quantity > 100) {
            throw new PanelError("invalid_quantity", "Informe de 1 a 100 números.");
        }
        $this->sync($company);
        $this->db->beginTransaction();
        try {
            $this->q("SELECT id FROM companies WHERE id=? FOR UPDATE", [$company]);
            $c = $this->q("SELECT * FROM number_contracts WHERE id=? AND company_id=? FOR UPDATE", [
                $id,
                $company,
            ])->fetch(\PDO::FETCH_ASSOC);
            if (!$c || !in_array($c["status"], ["paid", "active"], true)) {
                throw new PanelError("contract_inactive", "Contrato indisponível ou vencido.", 409);
            }
            $old = $this->q(
                "SELECT * FROM number_contract_orders WHERE contract_id=? AND request_key=?",
                [$id, $key],
            )->fetch(\PDO::FETCH_ASSOC);
            if ($old) {
                if ((int) $old["quantity"] !== $quantity) {
                    throw new PanelError("request_conflict", "Quantidade divergente.", 409);
                }
                $order = $old["service_request_id"];
            } else {
                $used = (int) $this->q(
                    "SELECT COALESCE(SUM(GREATEST(0,o.quantity-(SELECT COUNT(*) FROM number_fulfillments ff JOIN number_inventory ii ON ii.fulfillment_id=ff.id WHERE ff.service_request_id=o.service_request_id AND ii.status='failed'))),0) FROM number_contract_orders o WHERE o.contract_id=? AND o.cycle=? AND o.status<>'failed'",
                    [$id, $c["cycle"]],
                )->fetchColumn();
                if ($c["product"] === "online" && $quantity !== 1) {
                    throw new PanelError(
                        "invalid_quantity",
                        "O plano online entrega um número por vez.",
                    );
                }
                if (
                    $used + $quantity >
                    ($c["product"] === "online"
                        ? ((int) $c["cycle"] > 1 && $c["status"] === "active"
                            ? 10
                            : 11)
                        : 100)
                ) {
                    throw new PanelError(
                        "quota_exceeded",
                        "Limite de números ou trocas deste período atingido.",
                        409,
                    );
                }
                if (
                    $this->q(
                        "SELECT 1 FROM number_contract_orders o JOIN number_fulfillments f ON f.service_request_id=o.service_request_id WHERE o.contract_id=? AND o.status<>'failed' AND f.status IN ('quoted','paid') LIMIT 1",
                        [$id],
                    )->fetchColumn()
                ) {
                    throw new PanelError(
                        "delivery_pending",
                        "Aguarde a entrega anterior antes de solicitar outra.",
                        409,
                    );
                }
                $this->q(
                    "INSERT INTO service_requests(company_id,created_by,service,details,status) VALUES(?,?,'sms_number',?,'quoted')",
                    [
                        $company,
                        $user,
                        json_encode(
                            ["quantity" => $quantity, "ddd" => $ddd, "contract_id" => $id],
                            JSON_THROW_ON_ERROR,
                        ),
                    ],
                );
                $order = $this->db->lastInsertId();
                $this->q(
                    "INSERT INTO number_fulfillments(company_id,service_request_id,amount_cents,quote_note) VALUES(?,?,0,?)",
                    [$company, $order, "Entrega incluída no contrato #" . $id],
                );
                $this->q(
                    "INSERT INTO number_contract_orders(contract_id,cycle,service_request_id,quantity,request_key) VALUES(?,?,?,?,?)",
                    [$id, $c["cycle"], $order, $quantity, $key],
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        try {
            $result = $this->delivery->pay($company, [
                "id" => $order,
                "amount_cents" => 0,
                "version" => 1,
                "confirm" => true,
            ]);
            $this->q(
                "UPDATE number_contract_orders SET status='reserved' WHERE service_request_id=?",
                [$order],
            );
            return $result + ["order_id" => $order];
        } catch (PanelError $e) {
            if (in_array($e->errorCode, ["insufficient_stock", "order_state"], true)) {
                $this->q(
                    "UPDATE number_contract_orders SET status='failed' WHERE service_request_id=? AND status='pending'",
                    [$order],
                );
                $this->q(
                    "UPDATE number_fulfillments SET status='cancelled' WHERE service_request_id=? AND status='quoted'",
                    [$order],
                );
                $this->q(
                    "UPDATE service_requests SET status='cancelled' WHERE id=? AND status='quoted'",
                    [$order],
                );
            }
            throw $e;
        }
    }
    public function cancelPending(string $company, string $user, array $data): array
    {
        $id = PanelValidation::id($data["id"] ?? null);
        $note = PanelValidation::text($data, "note", 191);
        $this->db->beginTransaction();
        try {
            $this->q("SELECT id FROM companies WHERE id=? FOR UPDATE", [$company]);
            $c = $this->q("SELECT * FROM number_contracts WHERE id=? AND company_id=? FOR UPDATE", [
                $id,
                $company,
            ])->fetch(\PDO::FETCH_ASSOC);
            if (!$c) {
                throw new PanelError("not_found", "Contrato não encontrado.", 404);
            }
            if ($c["status"] === "cancelled") {
                $this->db->commit();
                return ["cancelled" => true, "replayed" => true];
            }
            if (
                $c["status"] !== "paid" ||
                $this->q(
                    "SELECT 1 FROM number_contract_orders o JOIN number_fulfillments f ON f.service_request_id=o.service_request_id JOIN number_inventory i ON i.fulfillment_id=f.id WHERE o.contract_id=? AND o.cycle=? AND i.status IN ('allocated','activating','delivered','retired') LIMIT 1",
                    [$id, $c["cycle"]],
                )->fetchColumn()
            ) {
                throw new PanelError(
                    "contract_delivering",
                    "Só é possível devolver um contrato pendente sem números separados ou entregues.",
                    409,
                );
            }
            $this->q("UPDATE number_contracts SET status='cancelled' WHERE id=?", [$id]);
            $this->q(
                "INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'reversal',?,?,UTC_TIMESTAMP())",
                [
                    $company,
                    hash("sha256", "contract-cancel:" . $id),
                    PaymentValidator::decimal((int) $c["price_cents"]),
                    "Devolução contrato #" . $id . " · " . $note,
                ],
            );
            $this->q(
                "INSERT INTO admin_events(user_id,action,details) VALUES(?,'contract_cancel',?)",
                [
                    $user,
                    json_encode(
                        ["company_id" => $company, "id" => $id, "note" => $note],
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            );
            $this->db->commit();
            return ["cancelled" => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}

<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__ . "/ChipeiraGateway.php";
require_once __DIR__ . "/PaymentValidator.php";

final class NumberDelivery
{
    private \PDO $db;
    private ChipeiraGateway $gateway;
    public function __construct(\PDO $db, ChipeiraGateway $gateway)
    {
        $this->db = $db;
        $this->gateway = $gateway;
    }
    private function q(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q;
    }
    public function stock(array $data, string $user): array
    {
        $op = PanelValidation::choice($data, "op", ["stock_add", "stock_retire"]);
        $note = PanelValidation::text($data, "note", 500);
        $this->db->beginTransaction();
        try {
            if ($op === "stock_add") {
                $phone = PanelValidation::text($data, "phone", 20);
                $slot = $data["slot"] ?? null;
                $type = PanelValidation::choice($data, "type", ["sms", "whatsapp"]);
                if (
                    !preg_match('/^\+55[1-9][0-9]{9,10}$/D', $phone) ||
                    !is_int($slot) ||
                    $slot < 1 ||
                    $slot > 64
                ) {
                    throw new PanelError(
                        "invalid_stock",
                        "Informe telefone com +55 e slot entre 1 e 64.",
                    );
                }
                if ($type === "whatsapp" && ($data["verified"] ?? false) !== true) {
                    throw new PanelError(
                        "verification_required",
                        "Confirme a verificação de acesso ao WhatsApp antes de oferecer garantia.",
                    );
                }
                if (
                    $this->q("SELECT id FROM number_inventory WHERE phone=? FOR UPDATE", [
                        $phone,
                    ])->fetchColumn()
                ) {
                    throw new PanelError(
                        "stock_exists",
                        "Número já cadastrado. Não será duplicado.",
                        409,
                    );
                }
                $this->q(
                    "INSERT INTO number_inventory(phone,slot,type,verification_note) VALUES(?,?,?,?)",
                    [$phone, $slot, $type, $note],
                );
                $id = $this->db->lastInsertId();
                $details = [
                    "id" => $id,
                    "phone" => $phone,
                    "type" => $type,
                    "slot" => $slot,
                    "note" => $note,
                ];
            } else {
                $id = PanelValidation::id($data["id"] ?? null);
                $row = $this->q("SELECT status FROM number_inventory WHERE id=? FOR UPDATE", [
                    $id,
                ])->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new PanelError("not_found", "Número não encontrado.", 404);
                }
                if (!in_array($row["status"], ["available", "retired"], true)) {
                    throw new PanelError(
                        "stock_locked",
                        "Número reservado ou entregue. Não pode voltar ao estoque por esta operação.",
                        409,
                    );
                }
                $this->q("UPDATE number_inventory SET status='retired' WHERE id=?", [$id]);
                $details = ["id" => $id, "note" => $note];
            }
            $this->audit($user, $op, $details);
            $this->db->commit();
            return ["saved" => true, "id" => $id];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function quote(string $company, array $data, string $user): array
    {
        $orderId = PanelValidation::id($data["id"] ?? null);
        if (
            $this->q("SELECT 1 FROM number_contract_orders WHERE service_request_id=?", [
                $orderId,
            ])->fetchColumn()
        ) {
            throw new PanelError(
                "contract_managed",
                "Entrega gerenciada pelo contrato. Não altere seu orçamento.",
                409,
            );
        }
        $amount = PaymentValidator::cents(PanelValidation::amount($data["amount"] ?? null));
        $note = PanelValidation::text($data, "note", 500);
        $this->db->beginTransaction();
        try {
            $this->q("SELECT id FROM companies WHERE id=? FOR UPDATE", [$company]);
            $order = $this->q(
                "SELECT * FROM service_requests WHERE company_id=? AND id=? AND service IN ('sms_number','whatsapp_number') FOR UPDATE",
                [$company, $orderId],
            )->fetch(\PDO::FETCH_ASSOC);
            if (
                !$order ||
                !in_array($order["status"], ["submitted", "reviewing", "quoted"], true)
            ) {
                throw new PanelError("order_state", "Pedido indisponível para orçamento.", 409);
            }
            $this->line($order);
            $old = $this->q(
                "SELECT * FROM number_fulfillments WHERE service_request_id=? FOR UPDATE",
                [$orderId],
            )->fetch(\PDO::FETCH_ASSOC);
            if ($old && !in_array($old["status"], ["quoted", "cancelled"], true)) {
                throw new PanelError("order_state", "Pedido já pago ou encerrado.", 409);
            }
            $this->q(
                "INSERT INTO number_fulfillments(company_id,service_request_id,amount_cents,quote_note) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE amount_cents=VALUES(amount_cents),quote_note=VALUES(quote_note),status='quoted',version=version+1",
                [$company, $orderId, $amount, $note],
            );
            $this->q("UPDATE service_requests SET status='quoted' WHERE id=?", [$orderId]);
            $this->audit($user, "number_quote", [
                "company_id" => $company,
                "id" => $orderId,
                "amount_cents" => $amount,
                "note" => $note,
            ]);
            $this->db->commit();
            return ["saved" => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    private function line(array $order): array
    {
        $line = json_decode($order["details"], true) ?: [];
        if (
            !isset($line["quantity"]) ||
            !is_int($line["quantity"]) ||
            $line["quantity"] < 1 ||
            $line["quantity"] > 1000
        ) {
            throw new PanelError(
                "invalid_order",
                "Pedido antigo sem quantidade válida. Registre um novo pedido.",
            );
        }
        return [
            "quantity" => $line["quantity"],
            "ddd" => $line["ddd"] ?? "",
            "type" => $order["service"] === "whatsapp_number" ? "whatsapp" : "sms",
        ];
    }
    public function pay(string $company, array $data): array
    {
        $id = PanelValidation::id($data["id"] ?? null);
        $this->db->beginTransaction();
        try {
            $this->q("SELECT id FROM companies WHERE id=? FOR UPDATE", [$company]);
            $order = $this->q(
                "SELECT * FROM service_requests WHERE company_id=? AND id=? FOR UPDATE",
                [$company, $id],
            )->fetch(\PDO::FETCH_ASSOC);
            $purchase = $this->q(
                "SELECT * FROM number_fulfillments WHERE company_id=? AND service_request_id=? FOR UPDATE",
                [$company, $id],
            )->fetch(\PDO::FETCH_ASSOC);
            if (!$order || !$purchase) {
                throw new PanelError("not_found", "Orçamento não encontrado.", 404);
            }
            if (in_array($purchase["status"], ["paid", "completed"], true)) {
                $this->db->commit();
                return ["paid" => true, "replayed" => true];
            }
            if ($purchase["status"] !== "quoted" || $order["status"] !== "quoted") {
                throw new PanelError("order_state", "Este orçamento não está disponível.", 409);
            }
            if (
                ($data["version"] ?? null) !== (int) $purchase["version"] ||
                ($data["amount_cents"] ?? null) !== (int) $purchase["amount_cents"] ||
                ($data["confirm"] ?? false) !== true
            ) {
                throw new PanelError(
                    "quote_changed",
                    "Revise o orçamento atualizado antes de confirmar.",
                    409,
                );
            }
            $expired = $this->q(
                "SELECT 1 FROM number_contract_orders o JOIN number_contracts c ON c.id=o.contract_id WHERE o.service_request_id=? AND (c.status IN ('expired','cancelled') OR c.expires_at<=UTC_TIMESTAMP())",
                [$id],
            )->fetchColumn();
            if ($expired) {
                throw new PanelError(
                    "contract_expired",
                    "Contrato vencido. Renove antes de solicitar uma entrega.",
                    409,
                );
            }
            $line = $this->line($order);
            $count = $line["quantity"];
            $amount = (int) $purchase["amount_cents"];
            $balance = (string) $this->q(
                "SELECT COALESCE(SUM(amount),0) FROM balance_entries WHERE company_id=?",
                [$company],
            )->fetchColumn();
            if (strpos($balance, "-") === 0 || PaymentValidator::cents($balance) < $amount) {
                throw new PanelError(
                    "insufficient_balance",
                    "Saldo insuficiente. Solicite uma recarga no Financeiro.",
                    409,
                );
            }
            $stock = $this->q(
                "SELECT id FROM number_inventory WHERE status='available' AND type=? AND phone LIKE ? ORDER BY id LIMIT " .
                    $count .
                    " FOR UPDATE",
                [$line["type"], $line["ddd"] === "" ? "%" : "+55" . $line["ddd"] . "%"],
            )->fetchAll(\PDO::FETCH_COLUMN);
            if (count($stock) !== $count) {
                throw new PanelError(
                    "insufficient_stock",
                    "Estoque insuficiente para este pedido. Seu saldo não foi alterado.",
                    409,
                );
            }
            foreach ($stock as $index => $item) {
                $cents = intdiv($amount, $count) + ($index < $amount % $count ? 1 : 0);
                $key = hash("sha256", "number-delivery:" . $purchase["id"] . ":" . $item);
                $this->q(
                    "UPDATE number_inventory SET status='allocated',company_id=?,fulfillment_id=?,delivery_key=?,item_cents=? WHERE id=?",
                    [$company, $purchase["id"], $key, $cents, $item],
                );
            }
            $this->q(
                "INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'debit',?,?,UTC_TIMESTAMP())",
                [
                    $company,
                    hash("sha256", "number-purchase:" . $purchase["id"]),
                    "-" . PaymentValidator::decimal($amount),
                    "Compra de números · pedido #" . $id,
                ],
            );
            $this->q(
                "UPDATE number_fulfillments SET status='paid',paid_at=UTC_TIMESTAMP() WHERE id=?",
                [$purchase["id"]],
            );
            $this->q("UPDATE service_requests SET status='accepted' WHERE id=?", [$id]);
            $this->db->commit();
            return ["paid" => true, "delivery_pending" => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function owned(string $company): array
    {
        if (class_exists(NumberContracts::class)) {
            (new NumberContracts($this->db, $this->gateway))->sync($company);
        }
        return $this->q(
            "SELECT i.id,i.phone,i.type,i.delivered_at,f.service_request_id order_id FROM number_inventory i JOIN number_fulfillments f ON f.id=i.fulfillment_id AND f.company_id=i.company_id LEFT JOIN number_contract_orders co ON co.service_request_id=f.service_request_id LEFT JOIN number_contracts c ON c.id=co.contract_id WHERE i.company_id=? AND i.status='delivered' AND f.status IN ('paid','completed') AND (c.id IS NULL OR c.product='subscription' OR (c.status IN ('paid','active') AND (c.expires_at IS NULL OR c.expires_at>UTC_TIMESTAMP()))) ORDER BY i.id DESC",
            [$company],
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function messages(string $company, array $data): array
    {
        if (class_exists(NumberContracts::class)) {
            (new NumberContracts($this->db, $this->gateway))->sync($company);
        }
        $id = PanelValidation::id($data["id"] ?? null);
        $item = $this->q(
            "SELECT phone FROM number_inventory WHERE id=? AND company_id=? AND status='delivered'",
            [$id, $company],
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$item) {
            throw new PanelError("not_found", "Número indisponível para esta conta.", 404);
        }
        $blocked = $this->q(
            "SELECT 1 FROM number_inventory i JOIN number_fulfillments f ON f.id=i.fulfillment_id JOIN number_contract_orders co ON co.service_request_id=f.service_request_id JOIN number_contracts c ON c.id=co.contract_id WHERE i.id=? AND c.product='online' AND (c.status IN ('expired','cancelled') OR c.expires_at<=UTC_TIMESTAMP())",
            [$id],
        )->fetchColumn();
        if ($blocked) {
            throw new PanelError("contract_expired", "O contrato do número venceu.", 403);
        }
        return $this->gateway->handle($company, "GET", [
            "op" => "messages",
            "number" => $item["phone"],
        ]);
    }
    public function quotes(string $company): array
    {
        return $this->q(
            'SELECT f.service_request_id order_id,f.amount_cents,f.version,f.quote_note,f.status,(SELECT COUNT(*) FROM number_inventory i WHERE i.fulfillment_id=f.id AND i.status=\'delivered\') delivered,(SELECT COUNT(*) FROM number_inventory i WHERE i.fulfillment_id=f.id AND i.status=\'failed\') failed FROM number_fulfillments f WHERE f.company_id=? ORDER BY f.id DESC LIMIT 100',
            [$company],
        )->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function deliver(string $company): array
    {
        $items = $this->q(
            "SELECT i.* FROM number_inventory i JOIN companies c ON c.id=i.company_id LEFT JOIN number_fulfillments cf ON cf.id=i.fulfillment_id LEFT JOIN number_contract_orders co ON co.service_request_id=cf.service_request_id LEFT JOIN number_contracts nc ON nc.id=co.contract_id WHERE i.company_id=? AND c.status='active' AND i.status IN ('allocated','activating') AND (nc.id IS NULL OR nc.product='subscription' OR (nc.status IN ('paid','active') AND (nc.expires_at IS NULL OR nc.expires_at>UTC_TIMESTAMP()))) ORDER BY i.id LIMIT 10",
            [$company],
        )->fetchAll(\PDO::FETCH_ASSOC);
        $delivered = 0;
        $pending = 0;
        $failed = 0;
        foreach ($items as $item) {
            $this->q(
                "UPDATE number_inventory SET status='activating' WHERE id=? AND status='allocated'",
                [$item["id"]],
            );
            try {
                $result = $this->gateway->handle($company, "POST", [
                    "op" => "deliver_number",
                    "number" => $item["phone"],
                    "slot" => (int) $item["slot"],
                    "type" => $item["type"],
                    "request_id" => $item["delivery_key"],
                ]);
                if (
                    ($result["delivered"] ?? false) !== true ||
                    ($result["phoneNumber"] ?? "") !== $item["phone"]
                ) {
                    throw new \RuntimeException("Delivery not confirmed");
                }
                $this->q(
                    "UPDATE number_inventory SET status='delivered',delivered_at=COALESCE(delivered_at,UTC_TIMESTAMP()),last_error=NULL WHERE id=? AND status='activating'",
                    [$item["id"]],
                );
                $delivered++;
            } catch (\Throwable $e) {
                // Somente recusas definitivas devolvem saldo. Timeout mantém o número separado.
                if ($e instanceof PanelError && in_array($e->httpStatus, [400, 404, 409], true)) {
                    $this->db->beginTransaction();
                    try {
                        $this->q("SELECT id FROM companies WHERE id=? FOR UPDATE", [$company]);
                        $current = $this->q(
                            "SELECT status FROM number_inventory WHERE id=? FOR UPDATE",
                            [$item["id"]],
                        )->fetchColumn();
                        if ($current === "activating") {
                            if ((int) $item["item_cents"] > 0) {
                                $this->q(
                                    "INSERT INTO balance_entries(company_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,'reversal',?,?,UTC_TIMESTAMP())",
                                    [
                                        $company,
                                        hash("sha256", "number-refund:" . $item["delivery_key"]),
                                        PaymentValidator::decimal((int) $item["item_cents"]),
                                        "Devolução de número indisponível · pedido #" .
                                        $item["fulfillment_id"],
                                    ],
                                );
                            }
                            $this->q(
                                "UPDATE number_inventory SET status='failed',last_error=? WHERE id=?",
                                [substr($e->getMessage(), 0, 500), $item["id"]],
                            );
                        }
                        $this->db->commit();
                        $failed++;
                    } catch (\Throwable $error) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        $pending++;
                    }
                } else {
                    $this->q(
                        'UPDATE number_inventory SET last_error=? WHERE id=? AND status=\'activating\'',
                        [
                            "Confirmação pendente na chipeira. Repetir com a mesma chave.",
                            $item["id"],
                        ],
                    );
                    $pending++;
                }
            }
        }
        $this->q(
            "UPDATE number_fulfillments f SET f.status='completed' WHERE f.company_id=? AND f.status='paid' AND NOT EXISTS(SELECT 1 FROM number_inventory i WHERE i.fulfillment_id=f.id AND i.status IN ('allocated','activating'))",
            [$company],
        );
        return ["delivered" => $delivered, "pending" => $pending, "failed" => $failed];
    }
    private function audit(string $user, string $action, array $details): void
    {
        $this->q("INSERT INTO admin_events(user_id,action,details) VALUES(?,?,?)", [
            $user,
            $action,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }
}

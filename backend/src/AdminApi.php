<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__ . "/SmsBilling.php";
require_once __DIR__ . "/NumberDelivery.php";

final class AdminApi
{
    private \PDO $db;
    private string $user;
    private array $chipeira;
    public function __construct(\PDO $db, string $user, array $chipeira)
    {
        $this->db = $db;
        $this->user = $user;
        $this->chipeira = $chipeira;
    }
    private function q(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q;
    }
    public function handle(string $method, array $data): array
    {
        if ($method === "GET") {
            return [
                "companies" => $this->q(
                    "SELECT c.id,c.name,c.contact_email,c.status,COALESCE(b.rate_mills,50) rate_mills,COALESCE(b.unlimited,0) unlimited,(SELECT COALESCE(SUM(amount),0) FROM balance_entries WHERE company_id=c.id) balance FROM companies c LEFT JOIN sms_billing_accounts b ON b.company_id=c.id WHERE c.deleted_at IS NULL ORDER BY c.id DESC LIMIT 200",
                )->fetchAll(\PDO::FETCH_ASSOC),
                "credits" => $this->q(
                    "SELECT r.id,r.company_id,c.name,r.amount,r.method,r.status,r.created_at FROM credit_requests r JOIN companies c ON c.id=r.company_id ORDER BY r.id DESC LIMIT 200",
                )->fetchAll(\PDO::FETCH_ASSOC),
                "orders" => $this->q(
                    "SELECT r.id,r.company_id,c.name,r.service,r.details,r.status,r.created_at,f.amount_cents,f.quote_note,f.status purchase_status FROM service_requests r JOIN companies c ON c.id=r.company_id LEFT JOIN number_fulfillments f ON f.service_request_id=r.id WHERE r.service IN ('sms_number','whatsapp_number') ORDER BY r.id DESC LIMIT 200",
                )->fetchAll(\PDO::FETCH_ASSOC),
                "charges" => $this->q(
                    "SELECT id,company_id,request_id,remote_id,reserved_cents,charged_cents,status,created_at FROM sms_charges ORDER BY id DESC LIMIT 200",
                )->fetchAll(\PDO::FETCH_ASSOC),
                "events" => $this->q(
                    "SELECT a.id,u.email,a.action,a.details,a.created_at FROM admin_events a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100",
                )->fetchAll(\PDO::FETCH_ASSOC),
                "inventory" => $this->q(
                    "SELECT id,phone,slot,type,verification_note,status,company_id,fulfillment_id,last_error,delivered_at FROM number_inventory ORDER BY id DESC LIMIT 500",
                )->fetchAll(\PDO::FETCH_ASSOC),
            ];
        }
        if ($method !== "POST") {
            throw new PanelError("method_not_allowed", "Use GET ou POST.", 405);
        }
        $op = PanelValidation::choice($data, "op", [
            "billing",
            "company_status",
            "credit",
            "order",
            "reconcile",
            "stock_add",
            "stock_retire",
            "number_quote",
            "deliver",
        ]);
        $delivery = new NumberDelivery($this->db, new ChipeiraGateway($this->chipeira));
        if (in_array($op, ["stock_add", "stock_retire"], true)) {
            return $delivery->stock($data, $this->user);
        }
        $company = PanelValidation::id($data["company_id"] ?? null);
        if ($op === "number_quote") {
            return $delivery->quote($company, $data, $this->user);
        }
        if ($op === "deliver") {
            return $delivery->deliver($company);
        }
        if ($op === "reconcile") {
            return (new SmsBilling($this->db, new ChipeiraGateway($this->chipeira)))->reconcile(
                $company,
            );
        }
        $this->db->beginTransaction();
        try {
            if (
                !$this->q("SELECT id FROM companies WHERE id=? AND deleted_at IS NULL FOR UPDATE", [
                    $company,
                ])->fetchColumn()
            ) {
                throw new PanelError("not_found", "Empresa não encontrada.", 404);
            }
            $details = [
                "company_id" => $company,
                "note" => PanelValidation::text($data, "note", 500),
            ];
            if ($op === "billing") {
                $rate = $data["rate_mills"] ?? null;
                $unlimited = $data["unlimited"] ?? null;
                if (!is_int($rate) || $rate < 1 || $rate > 10000 || !is_bool($unlimited)) {
                    throw new PanelError(
                        "invalid_rate",
                        'Informe tarifa entre R$ 0,001 e R$ 10,000.',
                    );
                }
                $this->q(
                    "INSERT INTO sms_billing_accounts(company_id,rate_mills,unlimited) VALUES(?,?,?) ON DUPLICATE KEY UPDATE rate_mills=VALUES(rate_mills),unlimited=VALUES(unlimited)",
                    [$company, $rate, (int) $unlimited],
                );
                $details += ["rate_mills" => $rate, "unlimited" => $unlimited];
            } elseif ($op === "company_status") {
                $status = PanelValidation::choice($data, "status", ["active", "suspended"]);
                $this->q("UPDATE companies SET status=? WHERE id=?", [$status, $company]);
                if ($status === "suspended") {
                    $this->q(
                        "UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE company_id=? AND revoked_at IS NULL",
                        [$company],
                    );
                }
                $details += ["status" => $status];
            } elseif ($op === "credit") {
                $id = PanelValidation::id($data["id"] ?? null);
                $status = PanelValidation::choice($data, "status", ["paid", "cancelled"]);
                $note = PanelValidation::text($data, "note", 191);
                $credit = $this->q(
                    "SELECT * FROM credit_requests WHERE company_id=? AND id=? FOR UPDATE",
                    [$company, $id],
                )->fetch(\PDO::FETCH_ASSOC);
                if (!$credit) {
                    throw new PanelError("not_found", "Recarga não encontrada.", 404);
                }
                if (
                    $this->q(
                        "SELECT id FROM payments WHERE company_id=? AND credit_request_id=? LIMIT 1",
                        [$company, $id],
                    )->fetchColumn()
                ) {
                    throw new PanelError(
                        "provider_managed",
                        "Confirme esta recarga pelo provedor de pagamentos.",
                        409,
                    );
                }
                if ($credit["status"] === $status) {
                    $this->db->commit();
                    return ["saved" => true, "replayed" => true];
                }
                if ($credit["status"] !== "pending") {
                    throw new PanelError("credit_closed", "Esta recarga já foi encerrada.", 409);
                }
                if ($status === "paid") {
                    $this->q(
                        "INSERT INTO balance_entries(company_id,credit_request_id,entry_key,type,amount,description,occurred_at) VALUES(?,?,?,'credit',?,?,UTC_TIMESTAMP())",
                        [
                            $company,
                            $id,
                            hash("sha256", "manual-credit:" . $id),
                            $credit["amount"],
                            "Pagamento conferido manualmente · " . $note,
                        ],
                    );
                }
                $this->q("UPDATE credit_requests SET status=?,provider_reference=? WHERE id=?", [
                    $status,
                    $note,
                    $id,
                ]);
                $details += ["id" => $id, "status" => $status, "note" => $note];
            } else {
                $id = PanelValidation::id($data["id"] ?? null);
                $status = PanelValidation::choice($data, "status", [
                    "reviewing",
                    "quoted",
                    "accepted",
                    "cancelled",
                ]);
                $note = PanelValidation::text($data, "note", 500);
                $order = $this->q(
                    "SELECT details,status FROM service_requests WHERE company_id=? AND id=? AND service IN ('sms_number','whatsapp_number') FOR UPDATE",
                    [$company, $id],
                )->fetch(\PDO::FETCH_ASSOC);
                if (!$order) {
                    throw new PanelError("not_found", "Pedido não encontrado.", 404);
                }
                $fulfillment = $this->q(
                    "SELECT id,status FROM number_fulfillments WHERE service_request_id=? FOR UPDATE",
                    [$id],
                )->fetch(\PDO::FETCH_ASSOC);
                if (
                    $fulfillment &&
                    (in_array($fulfillment["status"], ["paid", "completed"], true) ||
                        $status === "accepted")
                ) {
                    throw new PanelError(
                        "order_state",
                        "A compra deve ser confirmada pelo cliente. Pedidos pagos não podem ser cancelados por esta operação.",
                        409,
                    );
                }
                if ($fulfillment && in_array($status, ["reviewing", "cancelled"], true)) {
                    $this->q("UPDATE number_fulfillments SET status='cancelled' WHERE id=?", [
                        $fulfillment["id"],
                    ]);
                }
                $allowed = [
                    "submitted" => ["reviewing", "cancelled"],
                    "reviewing" => ["quoted", "cancelled"],
                    "quoted" => ["accepted", "reviewing", "cancelled"],
                    "accepted" => [],
                    "cancelled" => [],
                ];
                if (!in_array($status, $allowed[$order["status"]] ?? [], true)) {
                    throw new PanelError("order_state", "Transição de pedido inválida.", 409);
                }
                $body = json_decode($order["details"], true) ?: [];
                $body["admin_note"] = $note;
                $this->q(
                    "UPDATE service_requests SET status=?,details=? WHERE company_id=? AND id=?",
                    [
                        $status,
                        json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        $company,
                        $id,
                    ],
                );
                $details += ["id" => $id, "status" => $status, "note" => $note];
            }
            $this->q("INSERT INTO admin_events(user_id,action,details) VALUES(?,?,?)", [
                $this->user,
                $op,
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
}

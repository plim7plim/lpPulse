<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__ . "/EfiPix.php";
require_once __DIR__ . "/PaymentConfirmation.php";
require_once __DIR__ . "/SmsPurchases.php";

final class EfiPayments
{
    private \PDO $db;
    private EfiPix $gateway;
    public function __construct(\PDO $db, array $config, ?EfiPix $gateway = null)
    {
        $this->db = $db;
        $this->gateway = $gateway ?? new EfiPix($config);
    }
    public function ready(): bool
    {
        return $this->gateway->ready();
    }
    public function checkout(string $company, string $request): array
    {
        if (!$this->ready()) {
            throw new PanelError(
                "pix_not_configured",
                "Pix ainda não configurado. A solicitação permanece registrada.",
                503,
            );
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare("SELECT id FROM companies WHERE id=? FOR UPDATE");
            $q->execute([$company]);
            $q = $this->db->prepare(
                "SELECT * FROM credit_requests WHERE company_id=? AND id=? FOR UPDATE",
            );
            $q->execute([$company, $request]);
            $credit = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$credit || $credit["method"] !== "pix" || $credit["status"] !== "pending") {
                throw new PanelError(
                    "invalid_payment",
                    "Esta solicitação não aceita emissão de Pix.",
                    409,
                );
            }
            $q = $this->db->prepare(
                "SELECT * FROM payments WHERE company_id=? AND credit_request_id=?",
            );
            $q->execute([$company, $request]);
            $payment = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$payment) {
                $q = $this->db->prepare(
                    "INSERT INTO payments(company_id,credit_request_id,provider,provider_payment_id,external_reference,idempotency_key,amount) VALUES(?,?,?,?,?,?,?)",
                );
                $q->execute([
                    $company,
                    $request,
                    $this->gateway->name(),
                    bin2hex(random_bytes(16)),
                    bin2hex(random_bytes(32)),
                    hash("sha256", "efi:" . $company . ":" . $request),
                    $credit["amount"],
                ]);
                $q = $this->db->prepare("SELECT * FROM payments WHERE id=?");
                $q->execute([$this->db->lastInsertId()]);
                $payment = $q->fetch(\PDO::FETCH_ASSOC);
            }
            if ($payment["provider"] !== $this->gateway->name()) {
                throw new PanelError(
                    "payment_environment",
                    "Esta cobrança pertence a outro ambiente ou provedor.",
                    409,
                );
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        try {
            $pix = $this->gateway->checkout($payment);
        } catch (\Throwable $error) {
            throw new PanelError(
                "pix_pending",
                "A emissão do Pix não foi confirmada. Use Gerar Pix nesta mesma solicitação para tentar novamente.",
                503,
            );
        }
        $q = $this->db->prepare(
            "UPDATE payments SET status='pending' WHERE id=? AND status='creating'",
        );
        $q->execute([$payment["id"]]);
        return ["request_id" => $request, "amount" => $payment["amount"], "pix" => $pix];
    }
    public function reconcile(string $company, string $request): array
    {
        if (!$this->ready()) {
            throw new PanelError("pix_not_configured", "Pix ainda não configurado.", 503);
        }
        $q = $this->db->prepare(
            "SELECT provider_payment_id FROM payments WHERE company_id=? AND credit_request_id=? AND provider=?",
        );
        $q->execute([$company, $request, $this->gateway->name()]);
        $id = $q->fetchColumn();
        if (!$id) {
            throw new PanelError("payment_not_found", "Cobrança não encontrada.", 404);
        }
        return (new PaymentConfirmation(
            $this->db,
            $this->gateway,
            $this->gateway->merchant(),
        ))->reconcile($id);
    }
    public function reconcilePending(): int
    {
        if (!$this->ready()) {
            return 0;
        }
        $q = $this->db->prepare(
            "SELECT company_id,credit_request_id FROM payments WHERE provider=? AND (status IN ('creating','pending','approved','partially_refunded') OR (status='expired' AND updated_at>UTC_TIMESTAMP()-INTERVAL 1 DAY)) AND (checked_at IS NULL OR checked_at<UTC_TIMESTAMP()-INTERVAL 60 SECOND) ORDER BY COALESCE(checked_at,'1970-01-01'),id LIMIT 20",
        );
        $q->execute([$this->gateway->name()]);
        $count = 0;
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            try {
                $this->reconcile((string) $row["company_id"], (string) $row["credit_request_id"]);
                $count++;
            } catch (\Throwable $error) {
                $failed = $this->db->prepare(
                    "UPDATE payments SET checked_at=UTC_TIMESTAMP() WHERE company_id=? AND credit_request_id=? AND provider=?",
                );
                $failed->execute([
                    $row["company_id"],
                    $row["credit_request_id"],
                    $this->gateway->name(),
                ]);
                error_log(
                    "Pulse: reconciliação Efí pendente; pagamento será consultado novamente.",
                );
            }
        }
        return $count;
    }
}

<?php
declare(strict_types=1);

namespace Pulse;

use PDO;
use RuntimeException;
use Throwable;

final class PaymentConfirmation
{
    private PDO $db;
    private PaymentGateway $gateway;
    private string $merchantId;

    public function __construct(PDO $db, PaymentGateway $gateway, string $merchantId)
    {
        $this->db = $db;
        $this->gateway = $gateway;
        $this->merchantId = $merchantId;
    }

    /** Pode ser chamado por webhook autenticado ou tarefa de reconciliação. */
    public function reconcile(string $paymentId): array
    {
        if ($paymentId === '' || strlen($paymentId) > 191) {
            throw new RuntimeException('Identificador inválido.');
        }
        $provider = $this->gateway->name();
        $query = $this->db->prepare('SELECT * FROM payments WHERE provider = ? AND provider_payment_id = ?');
        $query->execute([$provider, $paymentId]);
        $initial = $query->fetch(PDO::FETCH_ASSOC);
        if (!$initial) {
            throw new RuntimeException('Pagamento não registrado.');
        }

        // Nunca manter transação aberta durante a chamada externa.
        $remote = $this->gateway->fetchPayment($paymentId);
        PaymentValidator::validate($remote, $initial, $this->merchantId);

        $this->db->beginTransaction();
        try {
            // Ordem global de bloqueio: empresa -> pagamento -> solicitação.
            $lock = $this->db->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
            $lock->execute([$initial['company_id']]);
            $query = $this->db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $query->execute([$initial['id']]);
            $local = $query->fetch(PDO::FETCH_ASSOC);
            if (!$local) {
                throw new RuntimeException('Pagamento indisponível.');
            }
            PaymentValidator::validate($remote, $local, $this->merchantId);
            $query = $this->db->prepare('SELECT * FROM credit_requests WHERE company_id = ? AND id = ? FOR UPDATE');
            $query->execute([$local['company_id'], $local['credit_request_id']]);
            $credit = $query->fetch(PDO::FETCH_ASSOC);
            if (!$credit || PaymentValidator::cents($credit['amount']) !== PaymentValidator::cents($local['amount'])) {
                throw new RuntimeException('Solicitação de crédito divergente.');
            }

            $amount = PaymentValidator::cents($local['amount']);
            $credited = PaymentValidator::cents($local['credited_amount']);
            $previousReversal = PaymentValidator::cents($local['reversed_amount']);
            $remoteReversal = PaymentValidator::cents($remote['reversed_amount']);
            $newReversal = max($previousReversal, $remoteReversal);
            $settled = in_array($remote['status'], ['approved', 'refunded', 'chargeback'], true);

            if ($settled && $credited === 0) {
                $this->entry($local, $amount, 'credit', 'credit', 'Crédito de pagamento confirmado');
                $credited = $amount;
            }
            if ($credited > 0 && $newReversal > $previousReversal) {
                $this->entry($local, $newReversal - $previousReversal, 'reversal', 'reversal:' . $newReversal, 'Estorno de pagamento confirmado');
            }

            // Respostas antigas não removem crédito ou desfazem estorno confirmado.
            if ($credited > 0) {
                $status = $newReversal === $amount ? 'refunded' : ($newReversal > 0 ? 'partially_refunded' : 'approved');
                $requestStatus = $newReversal === $amount ? 'cancelled' : 'paid';
            } else {
                $status = $remote['status'];
                $requestStatus = in_array($status, ['cancelled', 'rejected'], true) ? 'cancelled' : ($status === 'expired' ? 'expired' : 'pending');
            }
            $query = $this->db->prepare('UPDATE payments SET status = ?, credited_amount = ?, reversed_amount = ?, checked_at = UTC_TIMESTAMP() WHERE id = ?');
            $query->execute([$status, PaymentValidator::decimal($credited), PaymentValidator::decimal($newReversal), $local['id']]);
            $query = $this->db->prepare('UPDATE credit_requests SET status = ? WHERE company_id = ? AND id = ?');
            $query->execute([$requestStatus, $local['company_id'], $local['credit_request_id']]);
            $this->db->commit();
            return ['status' => $status, 'credited_amount' => PaymentValidator::decimal($credited), 'reversed_amount' => PaymentValidator::decimal($newReversal)];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function entry(array $payment, int $amount, string $type, string $suffix, string $description): void
    {
        $key = hash('sha256', 'payment:' . $payment['id'] . ':' . $suffix);
        $decimal = ($type === 'reversal' ? '-' : '') . PaymentValidator::decimal($amount);
        $query = $this->db->prepare('INSERT INTO balance_entries (company_id, credit_request_id, entry_key, type, amount, description, occurred_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $query->execute([$payment['company_id'], $payment['credit_request_id'], $key, $type, $decimal, $description]);
    }
}

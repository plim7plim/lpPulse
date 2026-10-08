<?php
declare(strict_types=1);

namespace Pulse;

use InvalidArgumentException;
use RuntimeException;

final class PaymentValidator
{
    public static function cents(string $amount): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?\z/', $amount, $parts)) {
            throw new InvalidArgumentException('Valor monetário inválido; use decimal com ponto.');
        }
        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Entrada vem exclusivamente de fetchPayment(), chamado no servidor. */
    public static function validate(array $remote, array $local, string $merchantId): void
    {
        foreach (['id', 'merchant_id', 'reference', 'currency', 'amount', 'status'] as $key) {
            if (!isset($remote[$key]) || !is_string($remote[$key])) {
                throw new RuntimeException('Resposta de pagamento incompleta.');
            }
        }
        if ($merchantId === '' || !hash_equals($merchantId, $remote['merchant_id'])) {
            throw new RuntimeException('Conta recebedora divergente.');
        }
        if (!hash_equals((string) $local['provider_payment_id'], $remote['id']) ||
            !hash_equals((string) $local['external_reference'], $remote['reference'])) {
            throw new RuntimeException('Referência do pagamento divergente.');
        }
        if (($local['currency'] ?? '') !== 'BRL' || $remote['currency'] !== 'BRL' || self::cents($remote['amount']) !== self::cents((string) $local['amount'])) {
            throw new RuntimeException('Valor ou moeda divergente.');
        }
        if (self::cents($remote['amount']) <= 0) {
            throw new RuntimeException('O valor precisa ser positivo.');
        }
        if (!in_array($remote['status'], ['pending', 'approved', 'cancelled', 'rejected', 'expired', 'refunded', 'chargeback'], true)) {
            throw new RuntimeException('Estado de pagamento desconhecido.');
        }
        // Adapter deve fornecer o valor cumulativo devolvido, inclusive chargebacks.
        if (!isset($remote['reversed_amount']) || !is_string($remote['reversed_amount'])) {
            throw new RuntimeException('Valor de estorno ausente.');
        }
        $reversed = self::cents($remote['reversed_amount']);
        if ($reversed > self::cents($remote['amount'])) {
            throw new RuntimeException('Estorno maior que o pagamento.');
        }
        if (in_array($remote['status'], ['refunded', 'chargeback'], true) && $reversed === 0) {
            throw new RuntimeException('Estorno não confirmado.');
        }
        if ($reversed > 0 && !in_array($remote['status'], ['approved', 'refunded', 'chargeback'], true)) {
            throw new RuntimeException('Estorno incompatível com o status.');
        }
    }
}

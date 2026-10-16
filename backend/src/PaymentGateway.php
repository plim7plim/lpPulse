<?php
declare(strict_types=1);

namespace Pulse;

/** O status de pagamento sempre deve ser consultado no servidor do provedor. */
interface PaymentGateway
{
    public function name(): string;

    /** Valida assinatura/token do webhook e retorna apenas o ID do pagamento. */
    public function authenticateWebhook(string $body, array $headers): string;

    /** Consulta autenticada à API do provedor e normaliza a resposta. */
    public function fetchPayment(string $paymentId): array;
}

<?php
declare(strict_types=1);

namespace Pulse;

/** Implementar somente após escolher o provedor. Nunca confiar no status enviado pelo navegador. */
interface PaymentGateway
{
    public function name(): string;

    /** Valida assinatura/token do webhook e retorna apenas o ID do pagamento. */
    public function authenticateWebhook(string $body, array $headers): string;

    /** Consulta autenticada à API do provedor e normaliza a resposta. */
    public function fetchPayment(string $paymentId): array;
}

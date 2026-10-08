<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PaymentValidator.php';

use Pulse\PaymentValidator;

function expectFailure(callable $callback): void {
    try { $callback(); } catch (Throwable $error) { return; }
    throw new RuntimeException('Era esperado rejeitar a entrada.');
}
if (PaymentValidator::cents('123.45') !== 12345 || PaymentValidator::cents('0.10') !== 10 || PaymentValidator::decimal(12345) !== '123.45') throw new RuntimeException('Conversão monetária falhou.');
foreach (['-1', '1,00', '1.001', '1e3', 'NaN', '01', '999999999999'] as $amount) expectFailure(fn() => PaymentValidator::cents($amount));
$local = ['provider_payment_id' => 'pay-123', 'external_reference' => 'credit-456', 'amount' => '100.00', 'currency' => 'BRL'];
$remote = ['id' => 'pay-123', 'merchant_id' => 'merchant-1', 'reference' => 'credit-456', 'amount' => '100.00', 'currency' => 'BRL', 'status' => 'approved', 'reversed_amount' => '0.00'];
PaymentValidator::validate($remote, $local, 'merchant-1');
$wrongCurrency = $local; $wrongCurrency['currency'] = 'USD';
expectFailure(fn() => PaymentValidator::validate($remote, $wrongCurrency, 'merchant-1'));
foreach (['id' => 'other', 'merchant_id' => 'other', 'reference' => 'other', 'currency' => 'USD', 'amount' => '99.99', 'status' => 'anything', 'reversed_amount' => '100.01'] as $field => $value) {
    $changed = $remote; $changed[$field] = $value;
    expectFailure(fn() => PaymentValidator::validate($changed, $local, 'merchant-1'));
}
$refund = $remote; $refund['status'] = 'refunded'; $refund['reversed_amount'] = '100.00';
PaymentValidator::validate($refund, $local, 'merchant-1');
$refund['reversed_amount'] = '0.00';
expectFailure(fn() => PaymentValidator::validate($refund, $local, 'merchant-1'));
echo "Validação monetária, recebedor, referência, moeda, status e estornos: OK\n";

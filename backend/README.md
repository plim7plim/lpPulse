# Confirmação de pagamentos

Base PHP 7.4+ / PHP 8, PDO MySQL e schema v3. Não depende de SDK ou framework.
O endpoint permanece desativado (HTTP 503) até configurar um provedor real.

## O que já existe

- Valores em centavos inteiros, sem arredondamento de FLOAT.
- Validação de moeda BRL, valor positivo, conta recebedora e referência local.
- Reconciliação usando consulta autenticada ao provedor, nunca status do browser.
- Crédito transacional: bloqueio de empresa/pagamento/solicitação e chave única
  no extrato. Reentrega de webhook não deve gerar crédito adicional.
- Estorno cumulativo e chargeback: lançamento negativo, sem apagar histórico.
- Respostas atrasadas não desfazem crédito nem estorno já reconhecido.
- Endpoint POST com autenticação do webhook pelo adapter, limite de tamanho e
  mensagens de erro que não expõem configurações.

## Pendências para operar

1. Escolher o provedor e implementar `PaymentGateway` usando sua documentação:
   assinatura/token do webhook e consulta autenticada do pagamento. O adapter
   deve rejeitar eventos inválidos, validar formato, identificar recebedor e
   normalizar os valores como strings decimais, não floats. Considerar replay e
   timestamps conforme as regras específicas do provedor.
2. Implementar a criação de cobrança (Pix/boleto/checkout), autenticação de
   clientes e API de recarga. O valor local vem da solicitação autorizada; registre
   em `payments` a referência e chave idempotente ANTES da criação externa.
   Retentativas devem reutilizar a mesma chave no provedor.
3. Configure arquivo PHP privado por `PULSE_CONFIG_PATH`, fora do webroot,
   seguindo `config.example.php`. Habilite PDO MySQL e HTTPS na hospedagem.
4. Faça a migração v3, configure o webhook HTTPS no provedor e crie uma tarefa
   de reconciliação de pagamentos pendentes. Webhook desconhecido retorna erro
   para tentar novamente após o registro da resposta de criação.
5. Validar tudo no sandbox com banco real: webhook inválido, valor/recebedor
   divergente, duplicidade, concorrência, timeout, aprovação, estorno parcial,
   total, chargeback e respostas fora de ordem. Só então ativar produção.

Nenhum endpoint público aceita `approved` vindo do cliente. O callback de retorno
do checkout apenas exibe o estado consultado; não libera saldo.

O adapter fornece `id`, `merchant_id`, `reference`, `currency`, `amount`, `status`
e `reversed_amount` (cumulativo, inclusive chargeback). Estados normalizados:
pending, approved, cancelled, rejected, expired, refunded, chargeback. O serviço
é limitado a recargas; pagamentos de faturas/assinaturas precisam de fluxo próprio.

Crédito e estorno podem deixar saldo negativo se houve consumo antes do estorno.
O worker deve bloquear novos envios sem saldo; não ignorar o débito do estorno.
Todas as operações de saldo precisam seguir a ordem de bloqueio da empresa.

## Importação e validação local

Banco vazio: `schema.sql` completo. Banco com v2: apenas
`database/migrations/003_payments.sql`. Não execute ambos.

```powershell
php backend/tests/payment_validator.php
```

Esse teste cobre validação de pagamentos, não concorrência ou um provedor real.
O ambiente local usa PHP com PDO MySQL e cURL e MariaDB em `127.0.0.1:3307`.
Inicialização e configuração privada em `../docs/banco-local.md`.

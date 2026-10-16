# Planos e contratos

Migração: importar `010_number_contracts.sql` depois de 009. O schema completo também contém as novas tabelas. Reexecutar 010 não duplica tabelas nem a coluna de modalidade dos lotes SMS.

## Número online

R$ 14,99 debitados do saldo em reais confirmado. A primeira solicitação entrega um número com SMS do estoque disponível; o período de 30 dias começa quando a chipeira confirma a primeira entrega. O cliente pode solicitar até 10 substituições por período, uma por vez. O número anterior permanece acessível até a confirmação do novo, e depois é retirado da consulta do cliente.

A entrega reaproveita NumberDelivery e as reservas condicionais existentes. Sem estoque, a solicitação falha sem consumir uma troca. Entrega ambígua permanece em conferência, evitando uma segunda reserva. Falhas definitivas devolvem a cota; entregas parciais de assinaturas descontam somente os itens que não falharam.

Renovação manual a partir das últimas 24 horas ou após o vencimento, pelo preço atual e com nova cota de trocas. Nenhum débito recorrente automático. Renovação de contrato online já vencido exige solicitar uma nova entrega; números antigos não são silenciosamente reativados.

No vencimento ou substituição, o estoque Pulse marca o número como retirado. O acesso ao SMS é bloqueado também pelo backend, mesmo se a rotina de reconciliação parar. O vínculo remoto na chipeira não é liberado automaticamente: permanece exclusivo até a conferência do administrador no hardware/estoque externo. Essa precaução evita revender um número ainda vinculado e misturar mensagens entre clientes. Não disponibilizar novamente apenas alterando o banco.

O administrador pode cancelar e devolver o valor de contratos online ainda pendentes, desde que nenhum número do período esteja separado ou entregue. A devolução e a ação ficam no extrato e na auditoria, sem duplicação.

## Assinatura

R$ 599,99 por período de 30 dias. A contratação libera cota de 100 números avulsos com SMS; não significa 100 slots simultâneos. O cliente solicita lotes conforme o estoque disponível. A quantidade é reservada no contrato antes da separação do estoque, e pedidos concorrentes não excedem a cota. O saldo em reais não é debitado novamente pelas entregas incluídas.

A renovação é manual, não transfere números não solicitados da cota anterior. Os números já entregues são avulsos e mantêm o comportamento de acesso existente; o vencimento encerra novas solicitações da assinatura. O plano não garante WhatsApp nos números entregues.

## Longcode

Financeiro: selecionar Créditos SMS longcode e informar a quantidade. Preço fixo de R$ 0,35 por segmento SMS. O administrador confirma o pagamento e libera um lote exclusivo da modalidade. O formulário Enviar SMS permite selecionar padrão ou longcode. As modalidades usam a mesma chipeira, e os créditos não se misturam. Longcode não fornece um serviço shortcode de operadora.

## Operação

O worker `reconcile-sms.php` acompanha entregas e validade. Após atualizar o PHP, reiniciar o worker para carregar o código novo; em produção agendar sua execução. As ações de contratação/renovação usam chaves idempotentes e confirmação explícita de preço. Operadores e leitores não podem contratar ou renovar.

Continuam externos: provedor de pagamentos automáticos, envio real de e-mails na hospedagem, API oficial de WhatsApp e fornecedor de eSIM. Esses produtos não são simulados como ativos. Aplicar o roteiro de produção e executar check-production no servidor com a configuração privada, sem usar as credenciais de teste.

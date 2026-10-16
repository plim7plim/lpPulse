# Compra de créditos SMS

Cada compra define sua própria tarifa: de 1 a 50.000 SMS, R$ 0,06 por unidade; de 50.001 em diante, R$ 0,05 por unidade. A tarifa vale para todas as unidades da compra. Compras anteriores, volume mensal e tamanho de campanha não alteram essa faixa.

Exemplos: 50.000 SMS = R$ 3.000,00; 50.001 SMS = R$ 2.500,05. O servidor calcula o valor usando `assets/tarifas.json` e ignora valores enviados pelo navegador para compras SMS.

No Financeiro, escolher Créditos SMS e informar a quantidade registra uma solicitação pendente. O administrador confere o recebimento e confirma a solicitação. Isso libera unidades SMS, separadas do saldo em reais destinado aos demais serviços. Não é gerado Pix ou boleto automaticamente: o provedor de pagamento ainda não foi escolhido. Compras SMS usam confirmação manual; a reconciliação genérica de pagamentos não transforma esses pacotes em dinheiro.

Cada lote guarda quantidade, tarifa e saldo restante. Envios consomem os lotes mais antigos primeiro. Uma mensagem com múltiplos segmentos consome um crédito por segmento e destinatário. Créditos reservados que não forem enviados retornam ao lote original; reenvios com a mesma chave e reconciliações repetidas não duplicam consumo ou devolução. A conta com disparos ilimitados mantém a isenção. O fluxo anterior de saldo em reais e tarifas específicas é preservado para clientes legados.

Antes de publicar, importar `database/migrations/009_sms_purchases.sql` no banco existente. Para banco novo, usar o schema completo. A migração cria tabelas e não altera compras, campanhas ou saldos antigos.

WhatsApp, eSIM, hospedagem e assinaturas aparecem na tabela comercial, mas não são automaticamente ativados pela compra de créditos SMS.

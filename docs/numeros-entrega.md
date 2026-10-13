# Estoque, compra e entrega de números

O cliente continua solicitando números avulsos por quantidade e modalidade. Não escolhe slots nem vê o estoque antes da compra.

## Operação

1. Na Administração, cadastre telefone E.164 (`+55` e DDD), slot identificado e modalidade. O cadastro não move chips nem reserva estoque externo. Para WhatsApp garantido, registre a verificação e marque a confirmação administrativa. O indicador do WhatsApp na chipeira também precisa estar positivo para entregar essa modalidade; ele sozinho não comprova que o aplicativo aceitará a ativação. Confira acesso, eventual PIN e demais condições antes de prometer a garantia.
2. Envie um orçamento do pedido, com valor TOTAL e condições para o cliente. A tarifa é definida por pedido; não foi inventada uma tabela de preços ou prazo de aluguel. A observação do orçamento é pública para o próprio cliente.
3. O proprietário ou gerente confirma a compra usando saldo. Operadores e usuários de consulta não podem gastar saldo comprando números. A exceção de SMS ilimitado não torna números gratuitos. O crédito continua vindo de recargas confirmadas pelo administrador ou pelo futuro provedor.
4. O servidor bloqueia a empresa, o pedido e as linhas do estoque, confere o saldo e a versão do orçamento e debita uma única vez. Se não houver a quantidade necessária, não cobra nem faz reserva parcial. O mesmo número nunca é alocado para dois pedidos. A soma dos valores de cada número é exatamente o total, inclusive em divisões com centavos.
5. A rotina automática chama a chipeira. Ela verifica número/slot, chip online, ausência de campanha em execução e reserva condicional no estoque dos vendedores. Somente depois cria o vínculo e confirma a entrega. O cliente vê o telefone apenas após essa confirmação.
6. Os SMS recebidos ficam em “Seus números”, dentro de Comprar números. Somente mensagens posteriores ao vínculo ficam visíveis. Para ativar o WhatsApp, o cliente usa seu celular e consulta aqui o SMS recebido. O Pulse não cria nem hospeda uma sessão de WhatsApp.

## Falhas e segurança

Uma chave persistente identifica cada entrega nos dois bancos. Timeout conserva o número separado e repete a mesma chave. Uma recusa definitiva devolve a parte correspondente ao saldo com lançamento único; o número recusado fica bloqueado para nova venda. Não recoloque números entregues ou em confirmação no estoque manualmente. Divergência de vínculo ou confirmação ambígua exige conferência, sem liberação automática.

Números vinculados ou com entrega pendente são excluídos de NOVAS campanhas do pool SMS. A entrega aguarda campanhas em andamento terminarem, evitando transferir um chip ainda usado por um disparo. A chave de integração da chipeira fica somente no PHP. A API do cliente não pode chamar a operação interna de entrega. Empresa suspensa não recebe novas entregas automáticas.

O administrativo permite retirar da venda somente números livres. Pedidos pagos não podem ser cancelados pelo antigo botão de alterar status. Números entregues não voltam automaticamente à venda. Devoluções comerciais posteriores à entrega e encerramento de contratos precisam de procedimento próprio; não apague vínculos no banco para reutilizar linhas.

## Instalação e execução

Importe `008_number_delivery.sql` em banco existente ou use `schema.sql` em banco vazio. Atualize também o backend da chipeira: `src/db/schema.sql`, `pulseDelivery.service.js`, `pulse.routes.js` e `soumaisbluNumbers.service.js`. Reinicie esse backend fora de uma campanha ativa. O SQLite recebe a tabela de idempotência pelo inicializador já existente.

`scripts/start-local.ps1` inicia PHP, MariaDB e a rotina `reconcile-sms.php --watch`. Ela confere campanhas e entregas a cada 30 segundos mesmo sem navegador aberto. Há trava de execução no diretório privado e PID separado; `stop-local.ps1` encerra a rotina antes do banco. Logs ficam em `C:/BLU/pulse-local/reconciliation.log` e `reconciliation-error.log`.

Em produção, mantenha a tarefa agendada `reconcile-sms.php` a cada minuto, sem `--watch`, ou um supervisor privado com `--watch`. O diretório da configuração precisa permitir a criação do arquivo de trava pelo usuário do processo. Não execute o worker através de endpoint HTTP. Se mudar a configuração, reinicie a rotina para recarregá-la.

Testes PHP: `backend/tests/number_delivery.php`. Testes da chipeira: `npm test -- --runInBand --runTestsByPath tests/pulse.api.test.js` no backend correspondente. As fixtures não enviam SMS nem reservam números no estoque real.

Ainda é necessário cadastrar estoque verdadeiro, verificar os números e validar uma compra autorizada de ponta a ponta no ambiente real. O código não transforma uma indicação de WhatsApp registrado em garantia da plataforma nem cria credenciais da hospedagem.

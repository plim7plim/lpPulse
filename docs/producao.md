# Publicação do Pulse

O código está preparado para PHP 7.4+ com PDO MySQL, cURL e iconv. Use MySQL/MariaDB, InnoDB e utf8mb4. Não use o servidor `php -S` em produção.

Gere o pacote com `scripts/build-production.ps1`. O ZIP contém `public/` e `private/`; somente `public/` vai para a pasta pública. O pacote não inclui credenciais, banco local, logs ou testes. A pasta privada inclui as classes necessárias aos comandos CLI. Configure `public_root` com o caminho absoluto da pasta pública para a verificação de produção.

## Locaweb

1. Crie um banco exclusivo de produção. Em banco vazio, importe `schema.sql` pelo phpMyAdmin. Em banco existente, faça backup e importe `database/migrations/006_sms_billing_admin.sql` e `database/migrations/007_registration.sql`. Não importe os dados locais de teste.
2. Publique `index.html`, `paginas/`, `css/`, `js/`, `assets/`, os demais assets referenciados e `.htaccess`, além de `backend/`. As pastas `backend/src/` precisam existir para o PHP, mas não podem ser acessadas pelo navegador. Não publique banco, testes, scripts, documentação ou configurações privadas.
3. Copie `backend/panel-config.example.php` para fora da pasta pública. Configure DSN, usuário restrito ao banco, senha, segredo aleatório de 32 bytes ou mais, origem HTTPS exata e cookie seguro. Configure `PULSE_CONFIG_PATH` no ambiente PHP da hospedagem apontando para esse arquivo. A forma de definir a variável depende do plano; verifique o painel/suporte da hospedagem. Não coloque segredos em JavaScript ou em `.htaccess`.
4. Defina `chipeira.base_url` como endereço HTTPS estável do backend da chipeira, com a mesma `PULSE_API_KEY`. Desative `allow_local_http`. O agente e o hardware continuam na máquina Windows; a hospedagem deve conseguir acessar o backend por conexão autenticada. Um túnel temporário não é endereço de produção.
5. Crie a conta administrativa via `scripts/create-admin.php` no terminal, com `PULSE_ADMIN_EMAIL` e `PULSE_ADMIN_PASSWORD` definidos apenas no processo. Inclua o ID retornado em `admin_user_ids` no arquivo privado. Para conta existente, inclua seu ID sem recriar o usuário. Ser proprietário de uma empresa não concede administração global.
6. Agende `php /caminho/privado/scripts/reconcile-sms.php` a cada minuto, com `PULSE_CONFIG_PATH` configurado. Mantenha scripts e migrações fora da pasta pública. Configure também `auth_mail.from` com um remetente autorizado e teste os links de cadastro e recuperação; detalhes em `docs/cadastro-senha.md`. A consulta do financeiro também reconcilia as campanhas, mas o agendamento garante devolução mesmo sem cliente conectado.
7. Execute `php scripts/check-production.php`. Depois teste login, acesso negado ao admin por cliente, recarga manual conferida, saldo insuficiente, campanha autorizada, extrato e cancelamento. O verificador não envia SMS e não confirma pagamentos.
8. Ative HTTPS, backups automáticos do banco e teste restauração. Confirme que URLs de `schema.sql`, arquivos ocultos, scripts e `backend/src/` retornam 403/404 no servidor.

## Cobrança

A tarifa padrão é R$ 0,050 por unidade SMS. A administração define a tarifa de cada empresa em milésimos de real; pacotes da landing page não alteram tarifas automaticamente. Texto GSM-7 usa 160 caracteres ou 153 por parte; Unicode usa 70 unidades UTF-16 ou 67 por parte. Caracteres estendidos GSM contam duas unidades. O total é arredondado para cima para centavos, uma vez por campanha.

Antes do envio, o valor máximo sai do saldo como reserva. A chave da campanha impede duplicação. Ao finalizar, são cobradas somente as mensagens marcadas como `sent` pela chipeira e a diferença retorna ao extrato. `sent` significa confirmação do modem, não confirmação de leitura ou entrega pela operadora. A tarifa da campanha fica congelada na reserva.

Timeout não libera saldo automaticamente: a mesma chave permite recuperar a campanha sem duplicar. A reconciliação pode reenviar uma criação pendente com a mesma chave, portanto mantenha o agendamento ativo. Falha explícita de validação/indisponibilidade de slots devolve a reserva. Campanha cancelada só é liquidada depois que todos os envios em andamento terminarem. Cancelamento seguido de reinício da chipeira pode exigir conferência operacional; nessa dúvida, a reserva é mantida. Não altere lançamentos diretamente no banco.

Contas ilimitadas são exceções explícitas e auditadas, destinadas a teste. O verificador sinaliza sua existência em produção. A conta local `teste@gmail.com` continua sem cobrança.

## Operação administrativa

Clientes: consultar saldo, ajustar tarifa, suspender/reativar e conferir campanhas. Recargas: confirmação manual com referência obrigatória, uma única liberação de saldo e bloqueio de confirmação manual para cobranças administradas por provedor. Pedidos: análise, orçamento, aceitação e cancelamento com histórico; esses estados não entregam nem cobram números automaticamente.

A confirmação manual exige conferência externa do recebimento. Pix/boleto automáticos continuam dependentes de um provedor. O painel não valida um comprovante sozinho.

## O que depende do ambiente

Domínio, credenciais da Locaweb, HTTPS, tarefa agendada, URL fixa da chipeira e provedor de pagamento não são criados pelo código. O pacote preparado não significa publicação concluída. Faça o teste de ponta a ponta no servidor antes de abrir a venda.

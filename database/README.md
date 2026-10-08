# Banco de dados Pulse

O `schema.sql` na raiz prepara um banco MySQL 5.6.5+ ou MariaDB 10.1+.
A versão específica da conta Locaweb ainda precisa ser confirmada. O script usa
InnoDB e `utf8mb4_unicode_ci`, sem recursos exclusivos do MySQL 8.

## Importação

1. Crie um banco MySQL no painel da Locaweb e abra o phpMyAdmin.
2. Selecione o banco vazio e confira a versão do servidor na página inicial.
3. Em **Importar**, escolha `schema.sql`, formato SQL e codificação UTF-8.
4. Execute uma única vez. O arquivo não cria o banco nem usuários de acesso.

O DDL não é transacional no MySQL: se a importação falhar, algumas tabelas podem
ter sido criadas. Confira o erro antes de repetir. Para atualizações futuras,
use migrações numeradas com `ALTER TABLE`, não reimporte a estrutura inicial.

## Organização

| Tabelas                                       | Finalidade                                             |
| --------------------------------------------- | ------------------------------------------------------ |
| companies, users, company_users               | Empresas, login e permissões por empresa               |
| auth_sessions, password_reset_tokens          | Sessões e recuperação de senha                         |
| contacts, contact_lists, contact_list_members | Contatos, consentimento e listas                       |
| campaigns                                     | Rascunhos, preparação, agendamento e execução          |
| campaign_recipients                           | Destinatários, fila, retries e estado de cada mensagem |
| delivery_events                               | Eventos do provedor e deduplicação de webhooks         |
| invoices                                      | Faturas das campanhas                                  |
| support_tickets, support_messages             | Atendimento no painel                                  |

## Integração com PHP

- Conecte pelo servidor usando PDO, consultas preparadas, `charset=utf8mb4`
  e exceções habilitadas. Credenciais ficam fora da pasta pública; não vão ao JS.
- Use `password_hash()` / `password_verify()` e cookies Secure, HttpOnly,
  SameSite. Guarde apenas SHA-256 dos tokens de sessão/reset e valide expiração.
- E-mails usam ASCII para manter índices compatíveis com InnoDB antigo;
  normalize o domínio internacional com IDNA e rejeite endereços não suportados.
- Aplique `company_id` em todas as consultas do cliente. Resolva a empresa por
  sessão e associação ativa; não confie no ID enviado pelo navegador.
- As chaves estrangeiras compostas impedem campanhas, listas e tickets de
  referenciar contatos ou membros de outra empresa. Não substituem autorização.
- Revogue associações pelo status em vez de apagar usuários com histórico.
  Operadores da Pulse que respondem tickets precisam de associação à empresa.
- Valide transições de status e valores monetários no backend; ENUM restringe os
  estados possíveis, mas não suas transições. `estimated_contacts` é apenas estimativa.
- Armazene horários em UTC, inclusive na sessão PDO, e exiba no fuso da empresa.

## Execução dos disparos

O schema não executa envios. Um worker deve materializar os destinatários em
lotes, deduplicar por campanha/contato e congelar telefone/mensagem no snapshot.
Antes de cada envio, conferir empresa ativa, campanha liberada e contato ativo
com consentimento concedido. Cancelar/suprimir destinatários que perderam consentimento.

Reivindique itens disponíveis em uma transação curta com `SELECT ... FOR UPDATE`
e `LIMIT`, ordenando por disponibilidade/ID. Atualize para `processing` com token
aleatório de lease e expiração, depois faça COMMIT. Faça a chamada externa fora
da transação. Ao atualizar o resultado, confira o token de lease e status.
Não dependa de `SKIP LOCKED`, ausente no MySQL 5.6/5.7.

Retries usam `available_at` e limite de tentativas; jobs de recuperação devem
reconciliar leases expirados. Se houve timeout após o provedor aceitar uma mensagem,
consulte o provedor antes de repetir. A chave local não garante envio único sem
idempotência ou reconciliação no provedor. Reuse a mesma chave nos retries.

Webhooks devem ter assinatura validada, empresa/destinatário resolvidos no
servidor e deduplicação por `event_key`. Eventos atrasados não devem regredir
`read` para `sent`; atualização do evento e destinatário deve ser transacional.

## Crescimento

Use paginação por ID/data, inserções em lotes e os índices de fila. Configure
retenção de eventos e limpeza de sessões/tokens expirados. Dados de envio geram
volume rapidamente; acompanhe armazenamento, conexões e limites do plano.
O desenho suporta evolução, mas a capacidade real depende da hospedagem e do worker.

Importar o SQL não configura a conexão PHP. A API atual está documentada em
`../docs/pulse-api.md`; o ambiente local usa as tabelas por PDO MySQL.

## Módulos do cliente (v2)

O schema completo já inclui preferências de pagamento, convites, solicitações
de crédito, extrato, telefonia/eSIM e parcerias. Para banco vazio, importe somente
`schema.sql`. Se você já importou o schema v1, execute somente
`migrations/002_client_modules.sql` uma vez, após backup. Não execute ambos.

Detalhes de serviços/parcerias são TEXT contendo JSON validado pela API por tipo.
Nenhuma tarifa, comissão ou disponibilidade de produto é presumida.

Saldo real é a soma dos lançamentos confirmados em `balance_entries`.
Solicitação de crédito não aumenta saldo: apenas um pagamento confirmado pelo
servidor gera lançamento com chave idempotente. Debitar saldo exige transação e
bloqueio da empresa, verificação de saldo suficiente e validação de sinal/valor.
Estornos são lançamentos novos; não editar o histórico.

Convites só podem ser enviados pelo backend com autorização de gerente/dono.
Valide papel e associação ativa antes de criar, aceitar ou revogar convites;
o token deve ser consumido uma única vez em transação. Nunca permitir ao cliente
atribuir a si mesmo permissões maiores.

No frontend, `supportWhatsApp` em `js/clientes.js` define o número de atendimento.
Está vazio até o proprietário fornecer o número. A API persiste solicitações;
registrar uma solicitação não ativa serviços nem confirma pagamentos.

## Confirmação de pagamentos (v3)

Banco v2: importe somente `migrations/003_payments.sql`. Banco vazio: importe
o schema completo, já atualizado para v5. Veja `../backend/README.md` para
configuração e limitações. Provedor ainda não escolhido; pagamentos reais desativados.

## Atendimento e notificações (v4)

Banco v3: executar apenas `migrations/004_support.sql` uma vez. Versões anteriores
precisam aplicar as migrations intermediárias em ordem. Banco vazio: importar
somente `schema.sql` (inclui todas as versões).

Tickets incluem categoria e contatos; a descrição é a primeira mensagem.
Anexos pertencem à mensagem e notificações ao usuário da empresa. Armazenar arquivos
fora da pasta pública com chave aleatória; verificar MIME pelo conteúdo, limite de
5 arquivos por solicitação e 10 MB por arquivo no servidor. Downloads exigem sessão
válida e autorização por empresa. Essas regras não são garantidas pelo SQL.

A API PHP persiste tickets, mensagens e notificações. O modo demonstrativo de
`js/suporte.js` continua em memória. Upload de anexos permanece indisponível.
O schema foi importado no MariaDB local; a configuração está em
`../docs/banco-local.md`. A migration `005_panel_api.sql` acrescenta os recursos
da API a um banco v4.

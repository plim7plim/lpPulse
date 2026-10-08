# API PHP do painel Pulse

A API persiste operações internas no MySQL. Não contrata telefonia/eSIM, não envia
mensagens ou e-mails, não gera Pix/boleto e não adiciona crédito a partir do browser.
`js/api.js` detecta a API no mesmo domínio. Sem PHP/configuração, mantém a
demonstração. Com API configurada, conecta login, perfil, preferências, usuários,
solicitações, recargas e consultas financeiras; sessão real é mantida somente em
cookie seguro, dados do servidor em memória. **Explorar demonstração** sempre
seleciona o namespace local separado, mesmo quando existir cookie autenticado.

## Configurar

PHP 7.4+ com PDO MySQL, schema v5. Banco novo: importar somente `schema.sql`.
Banco v4: importar somente `database/migrations/005_panel_api.sql`, uma vez após
backup. Sessões antigas exigem novo login. DDL/importação não foram testados neste
computador, que não tem driver PDO MySQL.

Arquivo PHP privado **fora do projeto e da raiz pública**, apontado pela variável
`PULSE_CONFIG_PATH`, com os campos existentes `dsn`, `username`, `password` e:

```php
'api_secret' => 'SEGREDO_ALEATORIO_DE_PELO_MENOS_32_CARACTERES',
'api_origin' => 'https://seu-dominio.com.br',
'cookie_secure' => true,
```

Gerar o segredo com `bin2hex(random_bytes(32))`; não publicar a configuração.
HTTPS é obrigatório por padrão, inclusive no PHP (`HTTPS=on` ou porta 443).
Em proxy reverso, configurar corretamente a variável HTTPS no servidor; a API
não confia em cabeçalhos de proxy enviados pelo cliente. Para desenvolvimento
somente em localhost, usar `api_origin` HTTP e `cookie_secure => false`.
O servidor Python da prévia não executa PHP. Exemplo local: `php -S 127.0.0.1:4174`.

Não existe criação pública de conta. Provisionar usuário com `password_hash()` e
status active, empresa pending/active e associação ativa em `company_users`.
Não usar os exemplos demonstrativos como credenciais. Empresa com vários usuários
é isolada pela sessão; usuário com várias empresas informa `company_id` no login.
Nunca selecionar empresa via parâmetros após autenticar.

## Contrato

Endpoint relativo `../backend/api.php?action=...` a partir de `paginas/clientes.html`.
Consultas GET, mutações POST com `Content-Type: application/json`. JSON objeto,
até 64 KiB. POST exige `Origin` idêntica à origem configurada. Sem CORS.
Resposta `{ok:true,data:{...}}`; erro `{ok:false,error:{code,message}}`.
IDs são strings; valores monetários são strings decimais como `"100.00"`, nunca floats.
Datas do banco estão em UTC (interpretar datas/horas como UTC no frontend).

Login cria cookie `pulse_session`, HttpOnly, SameSite=Lax, Secure por padrão, 8h.
Somente SHA256 do token aleatório é armazenado. `GET session` devolve
`authenticated,user:{id,name,email},company:{id,name},role,csrf_token,uploads_available:false`.
Sem sessão: `{authenticated:false}`. Todo POST autenticado envia
`X-CSRF-Token` recebido da sessão. POST login é a única exceção (continua exigindo Origin).

| action | GET | POST JSON |
|---|---|---|
| login | indisponível | `{email,password,company_id?}` retorna sessão |
| session | sessão | indisponível |
| logout | indisponível | `{}` revoga sessão |
| profile | `{profile}` da empresa | substitui campos editáveis, retorna perfil |
| requests | `?kind=service` ou `partnership`, `{items}` | criar/cancelar, exemplos abaixo |
| tickets | `{items}`; `?op=detail&id=...` retorna `{ticket,messages,attachments:[]}` | create/reply/close/reopen |
| notifications | `{items}` próprios | `{op:"read",id?}`; sem id marca todos próprios |
| billing_preferences | `{preferences}` ou null | `{billing_email,responsible_name,preferred_method}` |
| credit_requests | `{items}` | `{amount:"100.00",method:"pix"}` retorna pending e charge_created:false |
| invoices | `{items}` | indisponível |
| balance | `{balance:"0.00",items}` | indisponível |
| users | `{items}` (owner/manager) | criar ou revogar associação, sem e-mail |

Listas limitadas a 100 registros mais recentes; implementar paginação antes de
volumes maiores. Ticket detalhe retorna o histórico inteiro. Respostas nunca
incluem password_hash, tokens, referências do provedor ou credenciais.

Perfil usa `name` obrigatório, `legal_name`, `document_number`, `contact_phone`,
`contact_email`, `postal_code`, `address_line`, `address_complement`, `city`,
`state_code` (UF maiúscula). POST substitui esses campos; omissões ficam vazias.
Não altera status da empresa, permissão do usuário ou `profile_completed_at`:
a verificação cadastral completa exige política de negócio separada.

```json
{"kind":"service","op":"create","type":"virtual_number","details":{"locality":"São Paulo","quantity":1}}
```

Serviços: mobile_plan, travel_esim, virtual_number, pabx, whatsapp_number,
sms_number, sms_marketing, streaming, portability, toll_free, termination,
sip_trunk, whatsapp_attendance. Parcerias: affiliate, reseller.
`details` aceita até 30 campos simples (string até 2000 bytes, inteiro ou boolean),
total até 16 KiB. São solicitações de consulta, sem tarifa, estoque, aprovação ou
ativação automática; validar regras específicas com o prestador antes de contratar.
Cancelamento `{kind:"service",op:"cancel",id:"12"}` permitido somente nos estados
draft/submitted/reviewing/quoted/cancelled; não cancela contrato aprovado.

Ticket create: `{op:"create",category:"technical",description:"...",subject?,contact_phone?,contact_email?}`.
Categorias technical/commercial/financial/other. Descrição/resposta até 5000 bytes.
Reply `{op:"reply",id:"12",body:"..."}`; close/reopen `{op:"close",id:"12"}`.
Responder exige ticket aberto; reabrir exige encerrado. Criação, mensagem e
notificação são transacionais. Todos os membros da empresa podem consultar tickets,
mas só owner/manager/operator criam e respondem. Não há API administrativa da equipe.
Uploads estão desativados: `files`/`attachments` não vazios são rejeitados explicitamente.

Papéis: viewer consulta e marca próprias notificações; operator também solicita
serviços e opera tickets; owner/manager alteram cadastro, preferências financeiras,
pedem recarga e solicitam parceria. Logout permitido para qualquer papel autenticado.
Sessões revalidam associação, usuário e empresa em todas as requisições.

Usuários: `{op:"create",name,email,contact_phone?,password,role}` cria usuário
ativo com senha `password_hash()` e associação somente à empresa da sessão.
Senha 12–72 bytes. Owner pode criar manager/operator/viewer; manager cria
operator/viewer. Esta API nunca cria owner. E-mail existente é recusado, sem
vincular contas de outras empresas. Entregar acesso por processo autorizado fora
desta API; nenhum e-mail é enviado. `{op:"revoke",id}` revoga associação e sessões
daquela empresa; não apaga histórico. Nunca revoga owner, o próprio usuário ou,
para manager, outro manager. Sem edição de papéis/senha ou remoção definitiva.

## Verificação e operação

`php backend/tests/panel_validation.php` verifica entradas monetárias, IDs, UTF-8,
e-mails, detalhes, escolhas e rejeição de anexos. Lint PHP foi executado.
`php backend/tests/panel_policy.php` verifica gates de autenticação, CSRF,
papéis administrativos, rejeição de uploads e bloqueio de escrita de saldo.
Não houve teste integrado com banco, autenticação real, transações ou importação.
Antes de produção, testar isolamento entre empresas, papéis, CSRF/origem, expiração,
revogação, tentativas de login, alterações concorrentes de ticket e indisponibilidade.
Login limita 10 tentativas por combinação IP/e-mail a cada 15min, com contador
persistido e bloqueio transacional. Aplicar também rate limiting global na borda
contra IPs distribuídos e abuso. Limpar contadores antigos e sessões expiradas
periodicamente. Logs retornam somente tipo do erro; revisar infraestrutura de logs.
Pagamentos continuam dependendo da integração documentada em `backend/README.md`.

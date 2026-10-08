# Pulse + Chipeira

O navegador chama a API autenticada do Pulse. O PHP deriva o ID da empresa da
sessão e chama `/api/integration/pulse` na chipeira. A chave fica somente nos
servidores. Esta integração entrega SMS; não ativa WhatsApp, telefonia ou cobranças.

## Ativar

1. Atualizar o backend e o frontend da chipeira a partir de
   `C:\BLU\chipeira\chipeira`. As tabelas `pulse_numbers` e `pulse_campaigns`
   são criadas pelo schema SQLite no próximo início do backend. Os vínculos
   existentes de slots e o mapeamento físico são preservados.
2. Gerar uma chave aleatória de pelo menos 32 caracteres e definir `PULSE_API_KEY`
   em `backend/.env` da chipeira. Ela é diferente de `INTEGRATION_API_KEY`.
   Reiniciar somente o backend na janela de manutenção; o agente físico não
   precisa ser alterado. Construir o frontend com `npm run build:frontend`.
3. Na configuração PHP privada do Pulse, preencher o bloco `chipeira` do
   `backend/panel-config.example.php`: `enabled=true`, a mesma `api_key` e
   `base_url`. PHP 7.4+, PDO MySQL e cURL com HTTPS são necessários. Manter
   certificado verificado e configuração fora da raiz pública.
4. `base_url` vazio consulta `https://cscall.com.br/runtime-config.js`. Apenas
   túneis `*.trycloudflare.com` são aceitos automaticamente; para outro domínio,
   usar URL fixa HTTPS ou colocar o host exato em `discovery_hosts`. Não é
   necessário copiar a chave de integração geral para o Pulse.
5. No mapa da chipeira, editar o chip e preencher **Em uso por cliente Pulse**
   com o ID real de `companies.id` do banco Pulse. Apenas administradores podem
   atribuir ou remover. O campo vazio remove o vínculo. Em outra sessão do
   Pulse, entrar na empresa e abrir **Meus números**.

Alternativa administrativa pelo terminal da chipeira:

```powershell
node scripts/pulse-access.js --company 1 --phone 11987654321
node scripts/pulse-access.js --company 1 --list
node scripts/pulse-access.js --company 1 --phone 11987654321 --revoke
```

Os números acima são exemplos. Não executar atribuição sem identificar a empresa
e o chip corretos. A atribuição abre acesso às mensagens posteriores ao vínculo.
Troca de modem invalida o acesso até remover e refazer o vínculo. Para transferir
um número, primeiro remover o vínculo anterior. Campanhas em andamento bloqueiam
a transferência até serem concluídas ou canceladas.

## Telas e operações

- **Meus números:** número, operadora, online/offline e últimos 50 SMS recebidos.
- **SMS Marketing:** remetente atribuído, até 500 destinatários por campanha,
  mensagem, autorização e revisão antes de iniciar o envio real.
- **Campanhas:** andamento, enviados, falhas, resultado por contato e cancelamento
  dos destinatários pendentes. SMS já em voo podem terminar após o cancelamento.
- **Chipeira:** campo na edição, indicação nos cards, coluna na tabela/exportação
  e filtro no resumo de clientes Pulse. O ID da empresa é a referência entre os bancos.

O envio usa o motor e os intervalos existentes da chipeira. A mesma chave de
requisição e o mesmo conteúdo retornam a campanha anterior em vez de criar outra.
Após timeout, atualizar o histórico ou repetir sem editar o formulário; não há
reenvio automático de POST. Uma campanha pode ter sido aceita mesmo sem resposta.
O backend não retoma automaticamente campanhas interrompidas por reinício. Se
isso acontecer, conferir os destinatários e cancelar a campanha pendente antes
de preparar outra. Evitar manutenção durante disparos.

O saldo do Pulse ainda não é debitado pelo envio. Confirmar tarifas e regras de
faturamento antes de oferecer campanhas pagas. Recebimento pelo destinatário não
é comprovado pelo status `sent`: ele representa o envio aceito pelo modem.

## API Pulse

`backend/api.php?action=chipeira` mantém cookies, CSRF e papéis da API atual:

| Método | op        | Campos                                                        |
| ------ | --------- | ------------------------------------------------------------- |
| GET    | status    | —                                                             |
| GET    | phones    | —                                                             |
| GET    | messages  | number                                                        |
| GET    | campaigns | —                                                             |
| GET    | campaign  | id                                                            |
| POST   | create    | request_id, name, message, from[], recipients[], consent=true |
| POST   | cancel    | id                                                            |

Empresa e credencial enviados pelo navegador são ignorados. Leitores podem
consultar; proprietário, gerente e operador podem criar/cancelar campanhas.
Endpoints gerais da chipeira não são repassados ao cliente.

## Validação e ambiente local

```powershell
php backend/tests/chipeira_gateway.php
```

Na raiz da chipeira:

```powershell
npm test --workspace backend -- --runInBand pulse.api.test.js integration.api.test.js bulkSms.test.js slots.updateInfo.test.js
npm run build:frontend
```

Os testes automatizados usam SQLite em memória e comunicação serial simulada.
Nenhum SMS real é enviado por essas suítes. Para conexão real local, executar PHP
com PDO MySQL e cURL, banco configurado
e sessão autenticada. Usar `base_url=http://127.0.0.1:3001` somente com
`allow_local_http=true`, quando PHP e chipeira estão na mesma máquina. Na Locaweb,
`127.0.0.1` aponta para o servidor da hospedagem; usar a URL HTTPS do backend.

Nesta máquina, `scripts/start-local.ps1` usa PHP com PDO MySQL e cURL habilitados.
Em 07/10/2026, o slot 1 (TIM, número final 0386) foi vinculado à empresa 1001.
A configuração privada `panel-local.php` aponta para `http://127.0.0.1:3001`.
A campanha 18, criada pela API autenticada do Pulse, terminou com um destinatário
enviado e zero falhas. O usuário confirmou recebimento, mas relatou problema com
acentos; a correção de codificação é feita no agente serial. O status `sent`
indica confirmação do modem, não um comprovante de entrega da operadora.

A chave dedicada foi gerada no `backend/.env` da chipeira. Um bloco PHP com a
mesma chave está em `C:\BLU\pulse-private\chipeira.php`, fora da raiz pública.
Na configuração privada principal do Pulse, usar
`'chipeira' => require 'C:/BLU/pulse-private/chipeira.php'` em desenvolvimento,
ou transferir esse bloco para arquivo privado equivalente no servidor PHP.
O banco local está configurado; a chave não aparece no navegador. Não substituir
o bloco ativo pelo modelo sem ajustar `base_url` e `allow_local_http`.

Backup da publicação: `C:\BLU\chipeira-backups\site-1791392083075`.

## Codificação do SMS

O agente converte o texto para bytes do alfabeto GSM ou UCS-2 hexadecimal,
conforme os caracteres, e configura CSCS/CSMP antes de CMGS. Na opção UCS-2,
também converte o destino e restaura o charset GSM ao terminar. Essa sequência
permanece em uma única tarefa da fila serial, evitando intercalar outro envio.
O recebimento durante a janela UCS-2 é decodificado antes de encaminhar o evento.

Referência: [Quectel GSM SMS Application Note](https://quectel.com/content/uploads/2021/03/Quectel_GSM_SMS_Application_Note_V1.1.pdf).
Os testes de codificação, driver e bridge passaram (23 testes). A validação da
correção em um novo SMS depende de carregar o agente atualizado e confirmar o
texto recebido no aparelho; o primeiro SMS recebido foi anterior à correção.

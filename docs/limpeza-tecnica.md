# Limpeza técnica — 7 de outubro de 2026

## Resultado

Foram retirados 71 arquivos (23.95 MiB) das duas raízes: Pulse e chipeira. O conteúdo foi arquivado em `C:/BLU/cleanup-archive/2026-10-07`, fora das pastas servidas e executadas. O plano com justificativa por arquivo está em `removal-plan.json` nesse arquivo externo. Não houve exclusão de dados operacionais ou mudança de arquitetura.

O hash de 217 arquivos de código, protocolo, schema e configuração essencial foi comparado antes e depois: nenhum mudou. Arquivos de inicialização foram mantidos. Nenhum segredo aparece neste relatório.

## Critério de remoção

Inventário das duas raízes, análise dos imports CommonJS/ESM, includes PHP, scripts em package.json, referências HTML/CSS, exemplos de ambiente e scripts PowerShell. Arquivos sem importador não foram considerados automaticamente inúteis: pontos de entrada, testes descobertos por Jest e comandos manuais podem ser essenciais.

O middleware de `apis-chipeira` era uma cópia isolada, sem importadores e com dependência relativa inexistente. O middleware efetivamente montado em `backend/src/modules/auth/apiKey.middleware.js` permaneceu. Backups históricos não participam da leitura do mapa atual. Os diagnósticos em logs eram comandos manuais anteriores e não são chamados pela inicialização, watchdog ou código operacional.

## Arquivos retirados do Pulse

- `docs/rea-pulse.json`
- `docs/rea-brdid/findings.md`
- `docs/comparacao-suporte.md`
- `docs/painel-preview.png`
- `docs/banco-local-preview.png`
- `docs/preview.log`
- `docs/preview-error.log`

## Arquivos retirados da chipeira

- `apis-chipeira/backend/src/modules/auth/apiKey.middleware.js`
- `docs/API-INTEGRACAO copy.md`
- `deploy/backup-map-20260928-143840/hardware-profile.json`
- `deploy/backup-map-20260928-143840/portSlotMap.js`
- `deploy/backup-map-20260928-143840/PORT_MAPPING.md`
- `deploy/backup-map-20261001-150427/hardware-profile.json`
- `deploy/backup-map-20261001-150427/portSlotMap.js`
- `deploy/backup-map-20261001-150427/PORT_MAPPING.md`
- `deploy/backup-map-20261005-083642/hardware-profile.json`
- `deploy/backup-map-20261005-083642/portSlotMap.js`
- `deploy/backup-map-20261005-083642/PORT_MAPPING.md`
- `logs/admin-restart.log`
- `logs/agent-processes.json`
- `logs/apply-calibrated-map.cjs`
- `logs/before-physical-calibration-1791226932903/chipeira.sqlite`
- `logs/before-physical-calibration-1791226932903/hardware-profile.json`
- `logs/before-physical-calibration-1791226932903/portSlotMap.js`
- `logs/before-physical-calibration-1791226932903/PORT_MAPPING.md`
- `logs/calibrated-map-validation.json`
- `logs/diagnose-sms-bearer.cjs`
- `logs/diagnose-sms-claro.cjs`
- `logs/diagnose-sms-home.cjs`
- `logs/diagnose-sms-network.cjs`
- `logs/diagnose-sms-operator.cjs`
- `logs/diagnose-sms-register.cjs`
- `logs/diagnose-sms.cjs`
- `logs/full-restart-20261005.log`
- `logs/full-restart-20261005.ps1`
- `logs/inspect-agent-processes.ps1`
- `logs/inspect-four-slots.cjs`
- `logs/inspect-slot-health.cjs`
- `logs/inspect-sms-processes.ps1`
- `logs/launch-probe/probe.err.log`
- `logs/launch-probe/probe.log`
- `logs/recover-slots-5-31-34-44.log`
- `logs/recover-slots-5-31-34-44.ps1`
- `logs/refresh-sms-slot.cjs`
- `logs/reload-calibrated-backend.log`
- `logs/reload-calibrated-backend.ps1`
- `logs/remapeamento-validacao.csv`
- `logs/remapeamento-validacao.json`
- `logs/remove-duplicate-agent.log`
- `logs/remove-duplicate-agent.ps1`
- `logs/repair-sms-agent.ps1`
- `logs/restart-elevated.log`
- `logs/restart-sms-backend.ps1`
- `logs/restore-sms-network.cjs`
- `logs/run-sms-pdu.ps1`
- `logs/scan-sms-networks.cjs`
- `logs/sms-backend-restart.log`
- `logs/sms-lab.cjs`
- `logs/sms-pdu-isolated.cjs`
- `logs/sms-pdu-isolated.log`
- `logs/sms-pdu-restart.log`
- `logs/sms-processes.json`
- `logs/sms-repair.log`
- `logs/snapshot-calibration.cjs`
- `logs/test-concurrent-launch.ps1`
- `logs/test-new-sim-slots.cjs`
- `logs/test-sms-format.cjs`
- `logs/test-sms-requested.cjs`
- `logs/test-sms-reregister.cjs`
- `logs/validate-calibrated-map.cjs`
- `logs/verify-four-slots.cjs`

## Pastas retiradas

Pulse:

- `img/` (já vazia).
- `docs/rea-brdid/`.

Chipeira:

- `apis-chipeira/`, incluindo suas subpastas backend/src/modules/auth.
- `deploy/backup-map-20260928-143840/`.
- `deploy/backup-map-20261001-150427/`.
- `deploy/backup-map-20261005-083642/`.
- `logs/before-physical-calibration-1791226932903/`.
- `logs/launch-probe/`.

## Dependências

Removido `postcss` apenas da declaração direta de devDependencies de `frontend/package.json` e da entrada frontend no lockfile. Não há configuração própria ou import direto que necessite dessa declaração. O PostCSS transitivo do Vite permanece; nenhuma versão de pacote foi trocada. Todas as demais dependências tiveram uso identificado em código, CSS, scripts ou testes.

Não há package.json no Pulse: o site usa HTML/CSS/JavaScript e PHP, sem instalação npm própria. Não foi criado um manifesto artificial.

## Arquivos essenciais mantidos

Pulse:

- `paginas/clientes.html`, `js/chipeira.js`, `js/api.js`: controle do cliente e chamadas autenticadas.
- `backend/api.php`, `backend/src/PanelApi.php`, `PanelValidation.php`, `ChipeiraGateway.php`: sessão, empresa, papéis, CSRF, validação, HTTP e idempotência de envio.
- `schema.sql`, migrations e `database/seed-local.php`: banco e preparação local.
- `scripts/start-local.ps1`, `stop-local.ps1`, `backend/local-router.php`: execução PHP/MariaDB local.
- Exemplos de configuração, documentação atual e fontes locais com licença.

Chipeira:

- `backend/src/app.js`, config, banco, rotas, controllers, services, autenticação e auditoria.
- `backend/src/modules/integration/pulse.routes.js`, `pulseAssignments.js` e integração geral: chave privada, isolamento por empresa e vínculos de chips.
- Módulos devices, serial, SMS, identificação por USSD/chamada, saúde e sockets.
- `local-agent/src/index.js`, `bridge.js`, `events.js`, discovery, simWatch e `serial/serial.driver.js`: conexão ao backend e único acesso real às portas.
- `packages/protocol/`: ações, validação e timeouts compartilhados.
- Frontend de slots, mensagens, campanhas, login e usuários; assets de build e runtime-config.
- `deploy/hardware-calibration.json`, `hardware-profile.json`, `backend/src/db/portSlotMap.js`, `seedPortSlotMap.js`: mapa físico vigente.
- Scripts de stack, watchdog, inicialização automática, instalação de driver, rescan e publicação de URL; `scripts/pulse-access.js`.
- package.json e lockfile dos workspaces, exemplos de ambiente e credenciais privadas existentes (não modificadas).

## Fluxo final

`Navegador Pulse → backend/api.php → PanelApi (sessão e empresa) → ChipeiraGateway (cURL com chave privada) → /api/integration/pulse → serviços SMS/dispositivos → serial.proxy → Socket.IO + packages/protocol → local-agent/bridge → SerialPort → modem/SIM`.

Respostas e eventos voltam pelo agente, sockets e backend; registros ficam no SQLite da chipeira. O Pulse mantém usuários e sessões no MySQL/MariaDB. Chaves continuam fora do navegador. Vínculo por empresa, regras de remetente, consentimento, limites técnicos e idempotência permanecem iguais.

## Estrutura final

~~~text
C:/BLU/pulse/
├── backend/       API PHP, gateway, configuração modelo e testes
├── paginas/       painel do cliente
├── js/            scripts utilizados pelas páginas
├── css/           estilos utilizados
├── assets/fonts/  fontes locais e licença
├── database/      seed e migrations
├── scripts/       iniciar e parar ambiente local
├── docs/          documentação atual
├── index.html
├── clientes.html  redirecionamento compatível com links antigos
├── schema.sql
└── README.md

C:/BLU/chipeira/chipeira/
├── backend/       HTTP, autenticação, banco, SMS e proxy do agente
├── frontend/      painel de controle e build
├── local-agent/   Socket.IO e acesso serial
├── packages/protocol/
├── scripts/       execução, hardware e deploy em uso
├── deploy/        mapa, calibração e runtime-config atuais
├── integrations/  clientes externos e integração sou+blu
├── tools/         túnel e drivers
├── logs/          somente logs operacionais, PIDs e URL do túnel
├── docs/          documentação atual
├── package.json
├── package-lock.json
├── README.md
├── APIS.md
├── COMANDOS.md
├── DEPLOY.md
└── mapeamentoPortas.md
~~~

.git e node_modules foram omitidos da árvore por legibilidade e mantidos. Bancos, ambientes privados e runtime do Pulse fora dessas raízes foram preservados.

## Mantidos por segurança

- `ForcePasswordChangeModal.jsx` não tem importador atual, mas está ligado à autenticação e à troca de senha, com referências de estilo e método no AuthContext. Mantido por segurança; não foi ativado nem reescrito.
- Webhook e classes de pagamento no Pulse: endpoint existente, ainda sem provedor. Não foi presumido abandonado.
- Landing, módulos financeiros, suporte, cadastro e demonstração: estão carregados pelo painel e compartilham estado, navegação e eventos. Retirá-los exigiria mudar o comportamento e refatorar o frontend.
- Testes e mocks de serial: utilizados por Jest; a ausência de import em produção não os torna obsoletos.
- Scripts de recuperação, deploy e clientes externos: execução manual ou integração externa não se prova ausente apenas pelo grafo de imports local.
- Logs ativos, PIDs e `tunnel-published-url.txt`: lidos pelo watchdog e scripts; não foram truncados.
- Exemplos de ambiente: todos os nomes de variáveis têm referências no código ou scripts; configuração de hardware permaneceu intacta.

## Verificação

- PHP: gateway 18 verificações; validação 23; política 16; validação de pagamentos passou.
- Script start-local do Pulse: servidor PHP e MariaDB respondendo normalmente.
- Chipeira frontend: build passou e produziu os mesmos bundles `index-BekwcY-5.js` e `index-D4HfWl1U.css`.
- Protocolo: 13 testes passaram. Mapeamento: 4 passaram.
- Backend: 223 passaram, 1 falhou em simAbsence (mesma falha existente antes da limpeza).
- Agente: 31 passaram, 4 falharam em discovery.blockScan (mesmas falhas existentes antes da limpeza).
- Nenhuma falha nova nas suítes executadas; as cinco falhas anteriores foram mantidas visíveis, não removidas nem mascaradas.
- Backend ativo: /api/health HTTP 200; /api/health/agent informou connected=true.
- API real da chipeira: /api/integration/phones HTTP 200, 62 números; rota Pulse autenticada HTTP 200.
- Login de teste Pulse: HTTP 200, autenticação e ambiente local confirmados.
- Pulse permanece com integração desativada na configuração local e zero números vinculados à empresa 1001, como estava antes desta limpeza. O teste real de SMS solicitado anteriormente continua pendente; nenhum SMS foi enviado nesta revisão.

O backend e o agente de hardware em uso não foram reiniciados. Os testes carregam a aplicação em ambiente isolado; não foi aberta uma segunda sessão serial ou executado comando AT adicional. O teste de API comprova disponibilidade e conexão do agente, não entrega de SMS.

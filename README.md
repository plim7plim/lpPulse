# Pulse

Integração SMS com a chipeira: configuração, telas e limites em [docs/chipeira.md](docs/chipeira.md).

Cobrança de disparos, administração e publicação na Locaweb: [docs/producao.md](docs/producao.md).
Cadastro com confirmação de e-mail e recuperação de senha: [docs/cadastro-senha.md](docs/cadastro-senha.md).
Banco existente precisa das migrações `006_sms_billing_admin.sql` e `007_registration.sql`; banco vazio usa `schema.sql` atualizado.

## Estrutura

- `index.html`: landing page.
- `paginas/`: telas; `clientes.html` é o painel do cliente.
- `css/`: estilos da landing, painel e adaptação visual.
- `js/`: interações, animações, tema e módulos do painel.
- `assets/fonts/`: fontes locais.
- `backend/`: API autenticada do painel e preparação da confirmação de pagamentos em PHP.
- `schema.sql` e `database/`: estrutura MySQL/MariaDB e migrations.
- `docs/`: documentação da API, banco local e integração.

O `clientes.html` da raiz redireciona links antigos para `paginas/clientes.html`,
preservando a seção selecionada. Não é uma segunda cópia do painel.

## Executar localmente

Banco local de testes já preparado: executar `.\scripts\start-local.ps1`.
O painel PHP usa `http://127.0.0.1:4173/paginas/clientes.html`.
Contas, dados e comandos em [docs/banco-local.md](docs/banco-local.md).

O script inicia PHP e MariaDB locais. Para encerrar, execute
`.\scripts\stop-local.ps1`. Configuração e contratos em `docs/pulse-api.md`.
O login e os dados do painel usam a API PHP. Disparos pela chipeira são reais;
a conta local de teste tem cobrança desativada explicitamente.
O número de atendimento da landing deve ser configurado em `js/app.js`.
Pagamentos reais dependem do provedor e da configuração documentada no backend.

Para hospedar, gere o pacote com `scripts/build-production.ps1` e publique apenas
`public/`. A pasta `private/`, credenciais e banco devem permanecer fora do webroot.
Identidade do painel em `css/clientes.css`. A limpeza técnica e os arquivos
retirados estão registrados em [docs/limpeza-tecnica.md](docs/limpeza-tecnica.md).

# Pulse

Integração SMS com a chipeira: configuração, telas e limites em [docs/chipeira.md](docs/chipeira.md).

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
O login e os dados do painel usam a API PHP; a demonstração estática continua
disponível no frontend, mas não se comunica com a chipeira.
O número de atendimento deve ser configurado em `js/clientes.js`.
Pagamentos reais dependem do provedor e da configuração documentada no backend.

Para hospedar, servir a raiz preservando esta estrutura de pastas. Não publicar
credenciais ou configuração privada; `backend/config.example.php` é somente modelo.
Identidade do painel em `css/painel.css`. A limpeza técnica e os arquivos
retirados estão registrados em [docs/limpeza-tecnica.md](docs/limpeza-tecnica.md).

# Financeiro e importação de contatos

O painel recupera saldo, extrato, recargas, faturas e dados de cobrança nos endpoints existentes `balance`, `credit_requests`, `invoices` e `billing_preferences`.

Proprietários e gerentes podem solicitar recargas e editar cobrança. Operadores e perfis de consulta podem visualizar. Os dados são filtrados pela empresa da sessão; escritas continuam exigindo CSRF na API.

Uma recarga gera uma solicitação pendente no banco. Não gera cobrança nem credita saldo. O provedor de pagamentos ainda precisa ser escolhido e configurado. O envio de SMS pela chipeira ainda não desconta esse saldo.

## Planilha de contatos

- Excel `.xlsx` / `.xls`, CSV ou TSV; arquivo de até 2 MB.
- Cabeçalho `Telefone`; coluna `Nome` opcional. Um telefone com DDD por linha.
- Configure a coluna como texto. Não use fórmulas; células com fórmulas são ignoradas.
- Selecione a aba e a coluna na revisão. Duplicados são removidos; valores inválidos são apresentados e ignorados ao adicionar.
- Até 500 destinatários na lista final. Abas acima de 10.000 linhas são recusadas.
- A importação adiciona contatos ao campo. Não cria campanha nem envia mensagens; a revisão e a confirmação existentes continuam necessárias.

O modelo vazio está em `assets/modelo-contatos.csv`. A leitura acontece no navegador usando SheetJS CE 0.20.3, armazenado em `js/vendor`, com sua licença. O arquivo não é enviado ao servidor.

## Verificação

`node backend/tests/import_contacts.cjs` verifica formatos, duplicados, inválidos e leitura de Excel e CSV.

`php -c C:/BLU/pulse-local/php.ini backend/tests/finance_restored.php` verifica permissões, isolamento entre empresas e solicitações sem crédito, no banco `pulse_test`. As escritas do teste são revertidas.

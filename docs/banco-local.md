# Banco local de testes

O ambiente usa MariaDB 11.4.11 portátil em `C:\BLU\pulse-local`, com checksum
SHA-256 conferido contra o arquivo oficial do MariaDB. Não instala serviço do
Windows e não altera o banco da chipeira.

- Banco: `pulse_test`, `127.0.0.1:3307`.
- Dados persistentes: `C:\BLU\pulse-local\data`.
- API e painel PHP: `http://127.0.0.1:4173/paginas/clientes.html`.
- Configuração privada: `C:\BLU\pulse-private\panel-local.php`.
- Extensões PHP locais: PDO MySQL, cURL e OpenSSL, sem modificar o PHP global.

## Contas fictícias

| E-mail | Senha inicial | Perfil | Empresa |
| --- | --- | --- | --- |
| admin@pulse.test | PulseTeste123! | Proprietário | 1001 |
| operador@pulse.test | PulseTeste123! | Operador | 1001 |
| leitura@pulse.test | PulseTeste123! | Consulta | 1001 |
| outro@pulse.test | PulseTeste123! | Proprietário | 2001 |

O usuário solicitado `teste@gmail.com` também foi criado na empresa 1001. Sua
senha foi informada pelo usuário e gravada como hash. Nenhum e-mail foi enviado.
Essa conta tem perfil de proprietário e pode criar campanhas sem cota acumulada
de campanhas ou mensagens. A API atual não aplica cobrança por envio. O limite
de 500 destinatários por requisição é técnico; novas campanhas não consomem uma
cota da conta. A conexão real local com a chipeira foi ativada em 07/10/2026,
com o slot 1 vinculado à empresa 1001. Os disparos nessa integração são reais.

O seed inclui faturas, tickets, solicitações e saldo fictício de R$ 875,00.
O painel mostra **Ambiente de teste** porque o banco e os valores são fictícios.
Pagamentos ficam desligados. A integração SMS está ativa somente para os números
explicitamente vinculados à empresa; o envio usa hardware real.

## Iniciar e parar

Na raiz do Pulse:

```powershell
.\scripts\start-local.ps1
.\scripts\stop-local.ps1
```

Parar preserva os dados. O script não encerra processos de outras aplicações.
A prévia Python foi substituída pelo servidor PHP na mesma porta 4173.

Para executar novamente o seed, sem sobrescrever dados existentes:

```powershell
php -c C:/BLU/pulse-local/php.ini database/seed-local.php
```

## Verificações

```powershell
node backend/tests/local_api.mjs
```

As 26 verificações exercitam login/logout, sessão, CSRF, origem, gravação de
cadastro, saldo, faturas, recarga pendente, solicitações, tickets e permissões.
A segunda empresa confirma que tickets de outro cliente não ficam acessíveis.
O teste acrescenta recarga pendente, solicitação cancelada e ticket encerrado.
Nenhuma cobrança ou SMS real é executado.

## Estrutura

O banco foi importado diretamente de `schema.sql`: 24 tabelas, com chaves
estrangeiras ativas. O usuário PHP do banco tem somente SELECT, INSERT, UPDATE
e DELETE em `pulse_test`. A conta de administração do banco fica em arquivo
privado fora da pasta pública e é usada apenas para setup e encerramento.

O schema permanece compatível com os recursos MySQL/MariaDB previstos para a
Locaweb. A versão específica do banco da hospedagem ainda precisa ser confirmada.
Este ambiente não configura nem publica o banco de produção.

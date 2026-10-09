# Cadastro e recuperação de senha

O login possui links para criar conta e recuperar senha. A página `paginas/acesso.html` usa a mesma identidade visual do painel.

## Cadastro

Nome, empresa e e-mail são cadastrados como pendentes. Um link enviado ao e-mail permite definir a senha e ativar a conta. Novos clientes começam com saldo zero, tarifa padrão e sem administração ou disparos gratuitos. Nenhuma sessão é criada antes da confirmação do e-mail. Reenviar o cadastro pendente gera outro link e invalida o anterior, sem alterar os dados da conta.

## Recuperação

O servidor responde com a mesma mensagem para e-mails cadastrados e desconhecidos. O link contém 32 bytes aleatórios, dura 30 minutos e só funciona uma vez. Apenas seu SHA-256 é armazenado no banco. Ao trocar a senha, os demais links e todas as sessões do usuário são invalidados. Contas bloqueadas ou removidas não são reativadas pela recuperação.

Senhas usam `password_hash()` e possuem entre 12 e 72 bytes; nunca são enviadas por e-mail. As rotas públicas exigem POST JSON da origem configurada, com limite de cinco tentativas por IP e por destinatário em 15 minutos. O token vai no fragmento do link, é removido da barra após leitura e não aparece no log HTTP. Recarregar a tela de definição de senha exige reabrir o link do e-mail.

## Envio de e-mail

Configure `auth_mail.transport = 'mail'` e `auth_mail.from` com um remetente autorizado do seu domínio no arquivo PHP privado. O transporte usa a função `mail()` do servidor, sem dependências novas. Verifique as configurações de envio da hospedagem e a entrega na caixa de entrada e spam. A aceitação pela função `mail()` não comprova entrega ao destinatário. Sem configuração, a API recusa pedidos de cadastro e recuperação com uma mensagem clara; falhas durante o envio ficam no log do PHP sem token ou endereço do destinatário.

No ambiente local, `local_test = true` e `auth_mail.transport = 'file'` gravam mensagens `.eml` em `auth_mail.directory`, obrigatoriamente fora do projeto. A instalação local usa `C:/BLU/pulse-private/auth-outbox`. Abra o arquivo mais recente para testar seu link. Esses arquivos contêm links de acesso e não devem ser publicados. O transporte de arquivo é recusado fora do modo local.

Em banco existente, importe `database/migrations/007_registration.sql` pelo phpMyAdmin. Em banco vazio, use o `schema.sql` atualizado. Não é necessário mudar a integração com a chipeira.

Testes: `php -c C:/BLU/pulse-local/php.ini backend/tests/account_access.php`. Utilizam somente o banco `pulse_test`, uma pasta privada temporária e usuários temporários removidos ao final. Não enviam e-mails nem SMS reais.

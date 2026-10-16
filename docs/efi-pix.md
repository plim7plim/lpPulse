# Pix Efí

O financeiro cria uma solicitação com valor calculado no servidor. O botão **Gerar Pix** emite a cobrança daquela solicitação, mostra QR Code e copia e cola. Repetir o botão reutiliza o mesmo txid, inclusive após timeout. Uma nova solicitação representa outra compra.

O backend usa OAuth e certificado cliente P12 com validação TLS ativa. O navegador não recebe credenciais. Nenhum status recebido do cliente libera créditos: a confirmação consulta a Efí, compara conta recebedora, referência, moeda, valor e recebimentos, e bloqueia as linhas no MySQL antes de creditar. Recargas geram saldo; pacotes SMS/longcode liberam unidades, sem duplicar saldo em dinheiro.

## Configuração privada

Os arquivos locais ficam em `C:/BLU/pulse-private/efi/`. `panel-local.php` carrega `efi/config.php`. Preencha `client_id`, `client_secret` do ambiente selecionado e `pix_key`, depois habilite `enabled`. Não coloque esses arquivos no Git, na pasta pública ou nos assets. Não envie segredos pelo chat.

Homologação só funciona quando `local_test` é verdadeiro. Use uma base isolada de testes: a Efí pode confirmar automaticamente valores de R$ 0,01 a R$ 10,00. Isso gera créditos de teste na base local. Não misture esses dados com produção.

Na Locaweb, use `environment=producao` e certificado/credenciais de produção na configuração privada. O exemplo em `backend/panel-config.example.php` contém os campos. PHP precisa de cURL, OpenSSL e PDO MySQL. O certificado deve permanecer legível pelo PHP e fora do diretório público. Nunca desative a validação HTTPS.

## Confirmação

O botão **Verificar pagamento** consulta a cobrança autenticada. Para confirmação em segundo plano, agende `scripts/reconcile-sms.php` a cada minuto, definindo `PULSE_CONFIG_PATH`. O mesmo script também mantém a reconciliação da chipeira. No servidor local ele roda em modo `--watch`; reinicie o processo após alterar configuração privada.

O endpoint `backend/webhook.php` também aceita notificação Efí, mas exige mTLS validado pelo servidor web, com `SSL_CLIENT_VERIFY=SUCCESS` e cadeia de confiança cliente configurada para a Efí. Headers HTTP não substituem essa verificação. Se a hospedagem não suportar mTLS de entrada, use reconciliação agendada. Notificações em lote são tratadas pelo reconciliador, não por este webhook. Não foi registrado webhook externo automaticamente.

Estornos de saldo são conciliados de forma cumulativa. Devolução integral de pacote ainda não usado cancela suas unidades. Devolução parcial de pacote ou de unidades já usadas/reservadas exige conferência administrativa: não é convertida automaticamente em crédito ou dívida. O reconciliador registra a pendência; não dispara devoluções pela API.

## Verificação

`php scripts/check-efi.php` verifica os arquivos sem revelar chaves ou acessar a Efí. `php backend/tests/efi_pix.php` usa um provedor falso e transação descartável em `pulse_test`. Certificados válidos não provam que Client ID, Secret, chave Pix e permissões pertencem à mesma conta: a validação real de homologação só pode ocorrer após preencher esses dados.

Referências: https://dev.efipay.com.br/docs/api-pix/credenciais/ e https://dev.efipay.com.br/docs/api-pix/cobrancas-imediatas/.

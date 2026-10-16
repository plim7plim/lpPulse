<?php
declare(strict_types=1);
namespace Pulse;

require_once __DIR__ . "/PaymentGateway.php";
require_once __DIR__ . "/PaymentValidator.php";

final class EfiPix implements PaymentGateway
{
    private array $config;
    private array $credentials;
    private string $environment;
    private string $token = "";
    private $transport;

    public function __construct(array $config, ?callable $transport = null)
    {
        $this->environment = $config["environment"] ?? "homologacao";
        if (!in_array($this->environment, ["homologacao", "producao"], true)) {
            throw new \RuntimeException("Ambiente Efí inválido.");
        }
        $this->config = $config;
        $this->credentials = $config[$this->environment] ?? [];
        $this->transport = $transport;
    }

    public function ready(): bool
    {
        return !empty($this->config["enabled"]) &&
            !empty($this->config["pix_key"]) &&
            !empty($this->credentials["client_id"]) &&
            !empty($this->credentials["client_secret"]) &&
            is_readable($this->credentials["certificate"] ?? "");
    }

    public function merchant(): string
    {
        return (string) ($this->config["pix_key"] ?? "");
    }
    public function name(): string
    {
        return "efi_pix_" . $this->environment;
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->transport) {
            return ($this->transport)($method, $path, $body);
        }
        if (!$this->ready() || !extension_loaded("curl")) {
            throw new \RuntimeException("Pix Efí ainda não configurado.");
        }
        if ($path !== "/oauth/token" && $this->token === "") {
            $auth = $this->request("POST", "/oauth/token", ["grant_type" => "client_credentials"]);
            $this->token = (string) ($auth["access_token"] ?? "");
            if ($this->token === "") {
                throw new \RuntimeException("Autorização Efí indisponível.");
            }
        }
        $base =
            $this->environment === "producao"
                ? "https://pix.api.efipay.com.br"
                : "https://pix-h.api.efipay.com.br";
        $curl = curl_init($base . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERTTYPE => "P12",
            CURLOPT_SSLCERT => $this->credentials["certificate"],
            CURLOPT_SSLCERTPASSWD => $this->credentials["certificate_password"] ?? "",
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
        ];
        if (!empty($this->config["ca_bundle"])) {
            $options[CURLOPT_CAINFO] = $this->config["ca_bundle"];
        }
        if ($path === "/oauth/token") {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] =
                $this->credentials["client_id"] . ":" . $this->credentials["client_secret"];
        } else {
            $options[CURLOPT_HTTPHEADER][] = "Authorization: Bearer " . $this->token;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException("Comunicação com a Efí pendente (HTTP " . $status . ").");
        }
        $result = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new \RuntimeException("Resposta Efí inválida.");
        }
        return $result;
    }

    private function txid(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9]{26,35}$/D', $id)) {
            throw new \RuntimeException("Txid inválido.");
        }
        return $id;
    }

    public function checkout(array $payment): array
    {
        $id = $this->txid($payment["provider_payment_id"]);
        // Reconsultar antes de repetir uma criação cujo resultado pode ter sido perdido.
        try {
            $cob = $this->request("GET", "/v2/cob/" . $id);
        } catch (\Throwable $error) {
            $cob = $this->request("PUT", "/v2/cob/" . $id, [
                "calendario" => ["expiracao" => 3600],
                "valor" => ["original" => $payment["amount"]],
                "chave" => $this->merchant(),
                "solicitacaoPagador" => "Créditos Pulse",
                "infoAdicionais" => [
                    ["nome" => "Pulse", "valor" => $payment["external_reference"]],
                ],
            ]);
        }
        PaymentValidator::validate($this->normalize($cob), $payment, $this->merchant());
        $qr = [];
        if (isset($cob["loc"]["id"]) && ctype_digit((string) $cob["loc"]["id"])) {
            try {
                $qr = $this->request("GET", "/v2/loc/" . $cob["loc"]["id"] . "/qrcode");
            } catch (\Throwable $error) {
                /* Copia e cola continua disponível. */
            }
        }
        return [
            "txid" => $id,
            "copy_paste" => $cob["pixCopiaECola"] ?? ($qr["qrcode"] ?? ""),
            "qr_image" => $qr["imagemQrcode"] ?? "",
            "environment" => $this->environment,
            "expires_at" => isset($cob["calendario"]["criacao"])
                ? gmdate(
                    "c",
                    strtotime($cob["calendario"]["criacao"]) +
                        (int) ($cob["calendario"]["expiracao"] ?? 3600),
                )
                : null,
        ];
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->normalize($this->request("GET", "/v2/cob/" . $this->txid($paymentId)));
    }

    public function normalize(array $cob): array
    {
        $reference = "";
        foreach ($cob["infoAdicionais"] ?? [] as $info) {
            if (($info["nome"] ?? "") === "Pulse") {
                $reference = (string) ($info["valor"] ?? "");
            }
        }
        $amount = PaymentValidator::cents((string) ($cob["valor"]["original"] ?? ""));
        $paid = 0;
        $reversed = 0;
        $seen = [];
        foreach ($cob["pix"] ?? [] as $pix) {
            $end = $pix["endToEndId"] ?? "";
            if (!$end || isset($seen[$end])) {
                throw new \RuntimeException("Recebimento Pix inconsistente.");
            }
            $seen[$end] = true;
            $paid += PaymentValidator::cents((string) ($pix["valor"] ?? ""));
            foreach ($pix["devolucoes"] ?? [] as $refund) {
                if (($refund["status"] ?? "") === "DEVOLVIDO") {
                    $reversed += PaymentValidator::cents((string) $refund["valor"]);
                }
            }
        }
        $state = $cob["status"] ?? "";
        if (
            !in_array(
                $state,
                ["ATIVA", "CONCLUIDA", "REMOVIDA_PELO_USUARIO_RECEBEDOR", "REMOVIDA_PELO_PSP"],
                true,
            )
        ) {
            throw new \RuntimeException("Estado Pix desconhecido.");
        }
        if ($state === "CONCLUIDA" && $paid !== $amount) {
            throw new \RuntimeException("Recebimentos Pix divergentes.");
        }
        $status =
            $state === "CONCLUIDA"
                ? ($reversed === $amount
                    ? "refunded"
                    : "approved")
                : ($state === "ATIVA"
                    ? "pending"
                    : "cancelled");
        if (
            $status === "pending" &&
            isset($cob["calendario"]["criacao"]) &&
            strtotime($cob["calendario"]["criacao"]) +
                (int) ($cob["calendario"]["expiracao"] ?? 3600) <
                time()
        ) {
            $status = "expired";
        }
        return [
            "id" => (string) ($cob["txid"] ?? ""),
            "merchant_id" => (string) ($cob["chave"] ?? ""),
            "reference" => $reference,
            "currency" => "BRL",
            "amount" => PaymentValidator::decimal($amount),
            "status" => $status,
            "reversed_amount" => PaymentValidator::decimal($reversed),
        ];
    }

    public function authenticateWebhook(string $body, array $headers): string
    {
        // Apenas Apache/Nginx verificando o certificado cliente pode preencher esta variável.
        // Não aceitar headers HTTP como prova de mTLS. Sem mTLS, usar reconciliação agendada.
        if (($_SERVER["SSL_CLIENT_VERIFY"] ?? "") !== "SUCCESS") {
            throw new \RuntimeException("mTLS obrigatório.");
        }
        $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (count($event["pix"] ?? []) !== 1) {
            throw new \RuntimeException("Use o reconciliador para notificações em lote.");
        }
        return $this->txid((string) ($event["pix"][0]["txid"] ?? ""));
    }
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}

$path = getenv("PULSE_CONFIG_PATH");
if (!$path || !is_file($path)) {
    fwrite(STDERR, "Defina PULSE_CONFIG_PATH para a configuração privada.\n");
    exit(1);
}
$config = require $path;
$efi = $config["efi"] ?? [];
$environment = $efi["environment"] ?? "homologacao";
$credentials = $efi[$environment] ?? [];
echo "Ambiente: " . $environment . "\n";
foreach (["client_id", "client_secret"] as $field) {
    echo $field . ": " . (empty($credentials[$field]) ? "pendente" : "preenchido") . "\n";
}
echo "Chave Pix: " . (empty($efi["pix_key"]) ? "pendente" : "preenchida") . "\n";
$valid = extension_loaded("openssl");
echo "OpenSSL: " . ($valid ? "OK" : "indisponível") . "\n";
foreach (["homologacao", "producao"] as $environment) {
    $settings = $efi[$environment] ?? [];
    $bundle = [];
    $file = $settings["certificate"] ?? "";
    $readable = $valid && is_file($file) && is_readable($file);
    $parsed =
        $readable &&
        @openssl_pkcs12_read(
            file_get_contents($file),
            $bundle,
            $settings["certificate_password"] ?? "",
        );
    $details = $parsed ? openssl_x509_parse($bundle["cert"]) : false;
    $keyMatches = $parsed && openssl_x509_check_private_key($bundle["cert"], $bundle["pkey"]);
    $current =
        $details && $details["validFrom_time_t"] <= time() && $details["validTo_time_t"] > time();
    $ok = $parsed && $keyMatches && $current;
    echo $environment .
        ": " .
        ($ok ? "certificado e chave válidos" : "verifique certificado, senha ou validade") .
        "\n";
    $valid = $valid && $ok;
    unset($bundle);
}
$ca = $efi["ca_bundle"] ?? "";
$caOk = is_file($ca) && is_readable($ca) && openssl_x509_read(file_get_contents($ca)) !== false;
echo "CA HTTPS: " . ($caOk ? "OK" : "verificar") . "\n";
echo "Cobranças: " .
    (empty($efi["enabled"]) ? "desativadas" : "habilitadas na configuração") .
    "\n";
echo "Este teste não acessa a Efí nem cria cobranças.\n";
exit($valid && $caOk ? 0 : 1);

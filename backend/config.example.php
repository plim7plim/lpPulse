<?php
declare(strict_types=1);

// Copie para FORA da pasta pública e configure PULSE_CONFIG_PATH no servidor.
// Não preencher nem publicar credenciais neste arquivo de exemplo.
return [
    'dsn' => 'mysql:host=HOST_LOCAWEB;dbname=BANCO;charset=utf8mb4',
    'username' => '',
    'password' => '',
    'merchant_id' => '',
    // Instância de Pulse\PaymentGateway. null mantém o webhook desativado.
    'gateway' => null,
];

<?php
declare(strict_types=1);
// Copiar para FORA do projeto e do webroot. PULSE_CONFIG_PATH aponta para a cópia.
// Gerar api_secret via bin2hex(random_bytes(32)); não usar este texto literal.
return [
    'dsn'=>'mysql:host=HOST_LOCAWEB;dbname=BANCO;charset=utf8mb4',
    'username'=>'',
    'password'=>'',
    'api_secret'=>'',
    'api_origin'=>'https://seu-dominio.com.br',
    'cookie_secure'=>true,
    'merchant_id'=>'',
    'gateway'=>null,
    'chipeira'=>[
        'enabled'=>false,
        'api_key'=>'', // Mesmo PULSE_API_KEY da chipeira; nunca INTEGRATION_API_KEY.
        'base_url'=>'', // Vazio descobre o túnel via cscall.com.br/runtime-config.js.
        'allow_local_http'=>false,
        'discovery_hosts'=>[],
    ],
];

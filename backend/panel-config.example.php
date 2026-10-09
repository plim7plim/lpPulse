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
    'public_root'=>'', // Caminho absoluto da pasta pública, usado pelo verificador CLI.
    'cookie_secure'=>true,
    'admin_user_ids'=>[], // IDs de administradores da plataforma; proprietário de cliente não é administrador.
    'auth_mail'=>[
        'transport'=>'mail', // Envio via mail() do PHP; habilite no servidor e valide entrega.
        'from'=>'', // E-mail do seu domínio, autorizado pela hospedagem.
    ],
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

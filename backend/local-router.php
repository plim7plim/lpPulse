<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?? '/');
if (preg_match('#(?:^|/)(?:\.|database/|backend/tests/|scripts/)#',$path) || strpos($path,'..')!==false || preg_match('#\.(?:sql|ini|log|ps1|env)$#i',$path)) {
    http_response_code(404); echo 'Não encontrado.'; return true;
}
return false;

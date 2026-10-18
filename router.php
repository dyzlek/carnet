<?php
// Routeur pour le serveur intégré de PHP : bloque l'accès direct aux dossiers internes.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(data|src|pages|tools|dist|\.claude)(/|$)#', $path)) {
    http_response_code(403);
    exit('Interdit');
}
return false;

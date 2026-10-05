<?php
declare(strict_types=1);

// Routeur du serveur de développement : servir uniquement ces ressources publiques.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && in_array($path, ['/assets/app.css', '/assets/app.js'], true)) {
    return false;
}

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $config = bootstrap();
    handle_request($config);
} catch (Throwable $error) {
    error_log('Configuration indisponible (' . get_class($error) . ')');
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>ANRT</title><h1>Service indisponible</h1><p>La configuration doit être vérifiée par l’administrateur.</p></html>';
}

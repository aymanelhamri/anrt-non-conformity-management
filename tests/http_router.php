<?php
declare(strict_types=1);
// Routeur de recette uniquement, hors du dossier public et jamais déployé.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/assets/app.css', '/assets/app.js'], true)) { return false; }
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/FakeDatabase.php';
$config = bootstrap();
$pdo = new FakeDatabase(getenv('TEST_HTTP_STORE') ?: null);
$pdo->profiles = [$_SERVER['HTTP_X_TEST_PROFILE'] ?? 'RESPONSABLE'];
if (($_SERVER['HTTP_X_TEST_CLOSED'] ?? '') === 'true') { $config['permissions'] = []; }
handle_request($config, $pdo);

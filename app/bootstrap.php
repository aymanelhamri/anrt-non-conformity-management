<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/non_conformites.php';
require_once __DIR__ . '/web.php';

function bootstrap(): array
{
    if (PHP_INT_SIZE < 8) {
        throw new RuntimeException('PHP 64 bits est requis pour les identifiants INT UNSIGNED.');
    }
    date_default_timezone_set('UTC');
    return app_config();
}

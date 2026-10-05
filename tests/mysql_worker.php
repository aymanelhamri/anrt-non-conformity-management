<?php
declare(strict_types=1);
if (getenv('TEST_MYSQL') !== '1') { exit(1); }
require __DIR__ . '/mysql_support.php';
try {
    [$script, $name, $token, $marker, $slot] = $argv;
    $config = mysql_test_config($name);
    $pdo = database($config);
    file_put_contents($marker . '-' . $slot, 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($marker . '-go')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Barrière concurrente expirée.'); }
        usleep(10000);
        clearstatcache();
    }
    echo json_encode(create_nc($pdo, $config, mysql_user(), mysql_values(), $token, '127.0.0.1'), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, 'Échec worker (' . get_class($error) . ').');
    exit(1);
}

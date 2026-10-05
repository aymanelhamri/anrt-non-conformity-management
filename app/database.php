<?php
declare(strict_types=1);

function database(array $config): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => false,
    ];
    if ($config['mysql_ssl_ca']) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $config['mysql_ssl_ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $dsn = "mysql:host={$config['mysql_host']};port={$config['mysql_port']};"
        . "dbname={$config['mysql_database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['mysql_user'], $config['mysql_password'], $options);
    $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

function query(PDO $pdo, string $sql, array $parameters = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    foreach (array_values($parameters) as $index => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue($index + 1, $value, $type);
    }
    $statement->execute();
    return $statement;
}

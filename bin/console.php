<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $command = $argv[1] ?? '';
    if ($command === 'hash-password') {
        // Lire un secret pipé, sans l'exposer dans les arguments ou l'historique.
        if (stream_isatty(STDIN)) {
            throw new RuntimeException('Fournir le mot de passe par entrée standard protégée ; voir docs/implementation.md.');
        }
        $password = stream_get_contents(STDIN, 1026);
        $password = preg_replace('/\r?\n\z/', '', $password);
        if ($password === '' || strlen($password) > 1024 || !mb_check_encoding($password, 'UTF-8')) {
            throw new RuntimeException('Mot de passe vide, trop long ou UTF-8 invalide.');
        }
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new RuntimeException('Le générateur requiert une compilation PHP avec Argon2id.');
        }
        echo password_hash($password, PASSWORD_ARGON2ID), PHP_EOL;
        exit(0);
    }
    if ($command !== 'check-db') {
        throw new RuntimeException('Utilisation : php bin/console.php check-db | hash-password');
    }
    $pdo = database(bootstrap());
    $version = query($pdo, 'SELECT VERSION()')->fetchColumn();
    $timezone = query($pdo, 'SELECT @@session.time_zone')->fetchColumn();
    $procedure = query($pdo, "SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_name='generer_reference'")->fetchColumn();
    $status = query($pdo, "SELECT COUNT(*) FROM statuts WHERE code='OUVERTE'")->fetchColumn();
    if ((int) $procedure !== 1 || (int) $status !== 1) {
        throw new RuntimeException('La procédure ou le statut initial manque.');
    }
    echo "MySQL $version ; session $timezone ; procédure et statut présents", PHP_EOL;
} catch (PDOException $error) {
    fwrite(STDERR, 'Connexion MySQL indisponible (' . ($error->errorInfo[1] ?? $error->getCode()) . ').' . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

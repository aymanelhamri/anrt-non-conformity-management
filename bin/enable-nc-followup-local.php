<?php
declare(strict_types=1);

// Mise à niveau des droits du seul compte technique de démonstration existant.
// Ne touche ni aux comptes du site, ni aux dossiers, ni à la structure des tables.
require_once dirname(__DIR__) . '/app/bootstrap.php';
try {
    $config = bootstrap();
    if (PHP_SAPI !== 'cli' || $config['mysql_host'] !== '127.0.0.1' || (int) $config['mysql_port'] !== 3307
        || $config['mysql_database'] !== 'anrt_auth_local' || $config['mysql_user'] !== 'anrt_local' || !$config['demo']) {
        throw new RuntimeException('Cette commande est réservée à la démonstration locale.');
    }
    $settings = json_decode(file_get_contents(dirname(__DIR__) . '/instance/mysql-local/setup.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($settings['host'] !== $config['mysql_host'] || (int) $settings['port'] !== 3307 || $settings['user'] !== 'root') { throw new RuntimeException('Configuration locale incohérente.'); }
    $pdo = database([...$config, 'mysql_user' => $settings['user'], 'mysql_password' => $settings['password']]);
    $account = "'anrt_local'@'127.0.0.1'";
    $pdo->exec('GRANT UPDATE (type_nc_id, nature_service_id, description, traitement, responsable_matricule, date_echeance) ON `anrt_auth_local`.`non_conformites` TO ' . $account);
    $pdo->exec('GRANT INSERT, UPDATE (lue_le) ON `anrt_auth_local`.`notifications` TO ' . $account);
    echo "Droits de modification et de notification activés pour la démonstration locale.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Activation locale interrompue (' . get_class($error) . ").\n");
    exit(1);
}

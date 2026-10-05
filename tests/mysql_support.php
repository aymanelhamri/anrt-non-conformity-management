<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

function mysql_test_config(string $name): array
{
    if (!preg_match('/^anrt_recette_[a-f0-9]{32}$/D', $name)) {
        throw new RuntimeException('Seule une base de recette au nom généré est autorisée.');
    }
    return [
        'mysql_host' => getenv('TEST_MYSQL_HOST') ?: '127.0.0.1',
        'mysql_port' => getenv('TEST_MYSQL_PORT') ?: '3306',
        'mysql_user' => getenv('TEST_MYSQL_USER') ?: 'root',
        'mysql_password' => getenv('TEST_MYSQL_PASSWORD') ?: '', 'mysql_ssl_ca' => null,
        'mysql_database' => $name, 'secret' => getenv('TEST_SUBMISSION_SECRET'),
        'timezone' => 'UTC', 'demo' => true, 'initial_rules_confirmed' => false,
        'deadline_required' => false, 'permissions' => demo_policy(),
    ];
}

function execute_sql_script(PDO $pdo, string $path): void
{
    $source = file_get_contents($path);
    $source = preg_replace('/CREATE DATABASE IF NOT EXISTS anrt_qualite\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci;/', '', $source);
    $source = str_replace('USE anrt_qualite;', '', $source);
    $delimiter = ';';
    $buffer = '';
    foreach (explode("\n", $source) as $line) {
        $line = rtrim($line);
        if (trim($line) === '' || str_starts_with(ltrim($line), '--')) { continue; }
        if (str_starts_with($line, 'DELIMITER ')) { $delimiter = substr($line, 10); continue; }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($buffer), $delimiter)) {
            $pdo->exec(substr(rtrim($buffer), 0, -strlen($delimiter)));
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') { throw new RuntimeException('Script SQL incomplet.'); }
}

function fresh_mysql_database(): array
{
    $name = 'anrt_recette_' . bin2hex(random_bytes(16));
    $config = mysql_test_config($name);
    $pdo = new PDO("mysql:host={$config['mysql_host']};port={$config['mysql_port']};charset=utf8mb4",
        $config['mysql_user'], $config['mysql_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Aucune base existante n'est utilisée ou supprimée, même en cas de collision.
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");
    echo "Base de recette créée et conservée : $name\n";
    foreach (['001_schema.sql', '002_seed_referentiels.sql', '003_procedures.sql', '004_views.sql'] as $file) {
        execute_sql_script($pdo, dirname(__DIR__) . '/database/' . $file);
    }
    $pdo = database($config);
    $hash = password_hash('Recette-uniquement-2026', PASSWORD_ARGON2ID);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach (['00017' => ['-1 day', null], '00018' => ['-1 day', null], 'future' => ['+1 day', null], 'expired' => ['-2 days', '-1 day']] as $matricule => [$start, $end]) {
        query($pdo, 'INSERT INTO pers (matricule, nom_prenom) VALUES (?,?)', [(string) $matricule, 'Recette ' . $matricule]);
        query($pdo, "INSERT INTO acces (matricule, nom, passe, Date_Deb, Date_Fin) VALUES (?,'nom non unique',?,?,?)",
            [(string) $matricule, $hash, $now->modify($start)->format('Y-m-d H:i:s'), $end ? $now->modify($end)->format('Y-m-d H:i:s') : null]);
        query($pdo, "INSERT INTO acces_profil VALUES (?,'RESPONSABLE')", [(string) $matricule]);
    }
    return [$pdo, $config];
}

function mysql_counts(PDO $pdo): array
{
    $result = [];
    foreach (['non_conformites', 'journal_audit', 'compteurs_reference'] as $table) {
        $result[] = (int) query($pdo, "SELECT COUNT(*) FROM $table")->fetchColumn();
    }
    return $result;
}

function mysql_values(): array
{
    return ['processus_id' => '1', 'type_nc_id' => '1', 'nature_service_id' => '1',
        'description' => 'Constat de recette', 'traitement' => 'Traitement de recette',
        'responsable_matricule' => '00017', 'date_echeance' => ''];
}

function mysql_user(): array
{
    return ['matricule' => '00017', 'nom_prenom' => 'Compte de recette', 'profiles' => ['RESPONSABLE']];
}

<?php
declare(strict_types=1);

// Affectation de démonstration : 00001 valide les dossiers de 00020/00021/00022
// au niveau 1. Aucun profil n'est modifié et aucune NC n'est validée ici.
require_once dirname(__DIR__) . '/app/bootstrap.php';
try {
    $config = bootstrap();
    if (PHP_SAPI !== 'cli' || !$config['demo'] || $config['mysql_host'] !== '127.0.0.1'
        || (int) $config['mysql_port'] !== 3307 || $config['mysql_database'] !== 'anrt_auth_local') {
        throw new RuntimeException('Commande réservée à la démonstration locale.');
    }
    $settings = json_decode(file_get_contents(dirname(__DIR__) . '/instance/mysql-local/setup.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($settings['host'] !== '127.0.0.1' || (int) $settings['port'] !== 3307 || $settings['user'] !== 'root') { throw new RuntimeException('Configuration locale incohérente.'); }
    $pdo = database([...$config, 'mysql_user' => $settings['user'], 'mysql_password' => $settings['password']]);
    $pdo->beginTransaction();
    $validator = load_user($pdo, '00001', true);
    if (!$validator || $validator['nom_prenom'] !== 'Admin Demo') { throw new RuntimeException('Le compte de démonstration attendu est absent.'); }
    foreach (['00020' => 'Amine Demo', '00021' => 'Sara Demo', '00022' => 'Youssef Demo'] as $matricule => $name) {
        $declarant = load_user($pdo, $matricule, true);
        if (!$declarant || $declarant['nom_prenom'] !== $name) { throw new RuntimeException('Le déclarant de démonstration attendu est absent.'); }
        $existing = query($pdo, 'SELECT mat_val,niv_val FROM personnel_validation WHERE matricule=? FOR UPDATE', [$matricule])->fetchAll();
        if ($existing) {
            // Un réessai ne crée pas de doublon. Ne pas écraser une affectation
            // différente qui aurait été préparée manuellement entre-temps.
            if (count($existing) !== 1 || $existing[0]['mat_val'] !== '00001' || $existing[0]['niv_val'] !== '1') { throw new RuntimeException('Une autre affectation existe déjà.'); }
            continue;
        }
        query($pdo, 'INSERT INTO personnel_validation (matricule,nom_prenom,mat_val,nom_valideur,niv_val) VALUES (?,?,?,?,?)',
            [$matricule, $name, '00001', $validator['nom_prenom'], '1']);
    }
    $pdo->commit();
    echo "Validation de démonstration configurée : 00020, 00021, 00022 -> 00001, niveau 1.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'Configuration interrompue (' . get_class($error) . ").\n");
    exit(1);
}

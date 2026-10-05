<?php
declare(strict_types=1);
if (getenv('TEST_MYSQL') !== '1') {
    echo "13 cas MySQL non exécutés : activation explicite TEST_MYSQL=1 requise.\n";
    exit(0);
}
if (getenv('TEST_MYSQL_PASSWORD') === false) {
    fwrite(STDERR, "Configurer TEST_MYSQL_PASSWORD pour le serveur de recette.\n");
    exit(1);
}
require __DIR__ . '/mysql_support.php';
putenv('TEST_SUBMISSION_SECRET=' . bin2hex(random_bytes(32)));

function ensure(bool $condition): void
{
    if (!$condition) { throw new RuntimeException('Assertion MySQL échouée.'); }
}

function expect_failure(callable $operation, string $class): void
{
    try { $operation(); } catch (Throwable $error) {
        ensure($error instanceof $class);
        return;
    }
    throw new RuntimeException('Le refus attendu ne s’est pas produit.');
}

function concurrent_creations(array $config, bool $same): array
{
    $token = new_submission(mysql_user(), $config);
    $tokens = [$token, $same ? $token : new_submission(mysql_user(), $config)];
    $marker = sys_get_temp_dir() . '/anrt-mysql-barrier-' . bin2hex(random_bytes(8));
    $jobs = [];
    try {
        foreach ($tokens as $slot => $value) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/mysql_worker.php', $config['mysql_database'], $value, $marker, (string) $slot],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $jobs[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (!is_file($marker . '-0') || !is_file($marker . '-1')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Workers MySQL indisponibles.'); }
            usleep(10000);
            clearstatcache();
        }
        file_put_contents($marker . '-go', 'start');
        $results = [];
        foreach ($jobs as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 30);
            $output = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            ensure(proc_close($process) === 0);
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($jobs as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        }
        foreach (['-0', '-1', '-go'] as $suffix) { if (is_file($marker . $suffix)) { unlink($marker . $suffix); } }
    }
}

$tests = [];
$tests['Comptes valides, futurs, expirés et révocation'] = function (PDO $pdo, array $config): void {
    ensure(authenticate($pdo, '00017', 'Recette-uniquement-2026')['matricule'] === '00017');
    foreach (['17', 'future', 'expired'] as $identifier) { ensure(authenticate($pdo, $identifier, 'Recette-uniquement-2026') === null); }
    ensure(authenticate($pdo, '00017', 'incorrect') === null);
    query($pdo, "UPDATE acces SET Date_Fin=CURRENT_TIMESTAMP WHERE matricule='00017'");
    ensure(load_user($pdo, '00017') === null);
};
$tests['Création, audit et réessais'] = function (PDO $pdo, array $config): void {
    $token = new_submission(mysql_user(), $config);
    [$id, $replayed] = create_nc($pdo, $config, mysql_user(), mysql_values(), $token, null);
    ensure(!$replayed && create_nc($pdo, $config, mysql_user(), mysql_values(), $token, null) === [$id, true]);
    ensure(mysql_counts($pdo) === [1, 1, 1]);
    [$nc, $audit] = detail_nc($pdo, $config, mysql_user(), $id);
    ensure($nc['statut_code'] === 'OUVERTE' && count($audit) === 1);
    expect_failure(fn() => create_nc($pdo, $config, mysql_user(), [...mysql_values(), 'description' => 'autre'], $token, null), FormError::class);
    ensure(mysql_counts($pdo) === [1, 1, 1]);
};
foreach ([false, true] as $same) {
    $tests[$same ? 'Double soumission simultanée' : 'Créations simultanées distinctes'] = function (PDO $pdo, array $config) use ($same): void {
        [$first, $second] = concurrent_creations($config, $same);
        if ($same) {
            ensure($first[0] === $second[0] && $first[1] !== $second[1] && mysql_counts($pdo) === [1, 1, 1]);
        } else {
            ensure($first[0] !== $second[0] && mysql_counts($pdo) === [2, 2, 1]);
            ensure((int) query($pdo, 'SELECT COUNT(DISTINCT reference) FROM non_conformites')->fetchColumn() === 2);
        }
    };
}
$tests['Erreur audit : rollback NC et compteur'] = function (PDO $pdo, array $config): void {
    $pdo->exec("CREATE TRIGGER refuse_audit BEFORE INSERT ON journal_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Echec audit injecte'");
    expect_failure(fn() => create_nc($pdo, $config, mysql_user(), mysql_values(), new_submission(mysql_user(), $config), null), PDOException::class);
    ensure(mysql_counts($pdo) === [0, 0, 0]);
};
$tests['Réponse perdue après commit réel'] = function (PDO $pdo, array $config): void {
    $uncertain = new class($config) extends PDO {
        public function __construct(array $config) {
            parent::__construct("mysql:host={$config['mysql_host']};port={$config['mysql_port']};dbname={$config['mysql_database']};charset=utf8mb4",
                $config['mysql_user'], $config['mysql_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $this->exec("SET time_zone='+00:00'");
        }
        public function commit(): bool { parent::commit(); throw new PDOException('Réponse perdue après commit'); }
    };
    $token = new_submission(mysql_user(), $config);
    expect_failure(fn() => create_nc($uncertain, $config, mysql_user(), mysql_values(), $token, null), PDOException::class);
    [$id, $replayed] = create_nc($pdo, $config, mysql_user(), mysql_values(), $token, null);
    ensure($id > 0 && $replayed && mysql_counts($pdo) === [1, 1, 1]);
};
$tests['Références 999 et 1000'] = function (PDO $pdo, array $config): void {
    query($pdo, "INSERT INTO compteurs_reference VALUES ('NC',1,?,998)", [(int) substr(business_today($config), 0, 4)]);
    ensure(query($pdo, "SELECT LPAD('1000',3,'0')")->fetchColumn() === '100');
    [$id] = create_nc($pdo, $config, mysql_user(), mysql_values(), new_submission(mysql_user(), $config), null);
    ensure(str_contains(detail_nc($pdo, $config, mysql_user(), $id)[0]['reference'], '-999-'));
    expect_failure(fn() => create_nc($pdo, $config, mysql_user(), mysql_values(), new_submission(mysql_user(), $config), null), FormError::class);
    ensure((int) query($pdo, 'SELECT derniere_valeur FROM compteurs_reference')->fetchColumn() === 999);
};
foreach (['processus_id' => '999999', 'type_nc_id' => '999999', 'nature_service_id' => '999999', 'responsable_matricule' => 'inconnu'] as $field => $value) {
    $tests['Référence inconnue ' . $field] = function (PDO $pdo, array $config) use ($field, $value): void {
        expect_failure(fn() => create_nc($pdo, $config, mysql_user(), [...mysql_values(), $field => $value], new_submission(mysql_user(), $config), null), FormError::class);
        ensure(mysql_counts($pdo) === [0, 0, 0]);
    };
}
$tests['Référence inactive et affectation interdite'] = function (PDO $pdo, array $config): void {
    query($pdo, 'UPDATE types_nc SET actif=FALSE WHERE id=1');
    expect_failure(fn() => create_nc($pdo, $config, mysql_user(), mysql_values(), new_submission(mysql_user(), $config), null), FormError::class);
    query($pdo, 'UPDATE types_nc SET actif=TRUE WHERE id=1');
    expect_failure(fn() => create_nc($pdo, $config, mysql_user(), [...mysql_values(), 'responsable_matricule' => '00018'], new_submission(mysql_user(), $config), null), FormError::class);
    ensure(mysql_counts($pdo) === [0, 0, 0]);
};
$tests['Périmètres, filtres, pagination et historique inactif'] = function (PDO $pdo, array $config): void {
    $ids = [];
    for ($i = 0; $i < 21; $i++) { $ids[] = create_nc($pdo, $config, mysql_user(), mysql_values(), new_submission(mysql_user(), $config), null)[0]; }
    $other = [...mysql_user(), 'matricule' => '00018'];
    [$otherId] = create_nc($pdo, $config, $other, [...mysql_values(), 'responsable_matricule' => '00018'], new_submission($other, $config), null);
    $first = list_nc($pdo, $config, mysql_user(), [], 1);
    $second = list_nc($pdo, $config, mysql_user(), [], 2);
    ensure($first['total'] === 21 && count($first['rows']) === 20 && count($second['rows']) === 1);
    ensure(!array_intersect(array_column($first['rows'], 'id'), array_column($second['rows'], 'id')));
    ensure(detail_nc($pdo, $config, mysql_user(), $otherId) === [null, []]);
    foreach (['responsable_matricule' => '00018', 'statut_code' => 'CLOTUREE'] as $field => $value) { ensure(list_nc($pdo, $config, mysql_user(), [$field => $value], 1)['total'] === 0); }
    $reference = detail_nc($pdo, $config, mysql_user(), $ids[0])[0]['reference'];
    ensure(list_nc($pdo, $config, mysql_user(), ['reference' => $reference], 1)['total'] === 1);
    query($pdo, 'UPDATE processus SET actif=FALSE WHERE id=1');
    ensure(detail_nc($pdo, $config, mysql_user(), $ids[0])[0]['id'] == $ids[0]);
    ensure(list_nc($pdo, $config, mysql_user(), ['processus_id' => '1'], 1)['total'] === 21);
};

$failures = 0;
foreach ($tests as $name => $operation) {
    try { [$pdo, $config] = fresh_mysql_database(); $operation($pdo, $config); echo "OK $name\n"; }
    catch (Throwable $error) { $failures++; echo 'ECHEC ', $name, ' (', get_class($error), ").\n"; }
}
echo count($tests) - $failures, ' tests MySQL réussis, ', $failures, " échec(s).\n";
exit($failures === 0 ? 0 : 1);

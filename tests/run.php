<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/FakeDatabase.php';

function check(bool $condition, string $message = 'Assertion échouée'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $operation, string $class = FormError::class, ?int $status = null): void
{
    try { $operation(); } catch (Throwable $error) {
        check($error instanceof $class, 'Type inattendu : ' . get_class($error));
        if ($status !== null) { check($error->status === $status, 'Statut inattendu'); }
        return;
    }
    throw new RuntimeException('Le refus attendu ne s’est pas produit.');
}

$config = [
    'secret' => 'test-only-not-production-12345678901234567890', 'permissions' => demo_policy(),
    'demo' => false, 'initial_rules_confirmed' => true, 'deadline_required' => false,
    'mysql_database' => 'test', 'timezone' => 'UTC',
];
$user = ['matricule' => '00017', 'nom_prenom' => 'Compte test', 'profiles' => ['RESPONSABLE']];
$values = ['processus_id' => '1', 'type_nc_id' => '1', 'nature_service_id' => '1',
    'description' => 'Un constat précis', 'traitement' => 'Un traitement réel',
    'responsable_matricule' => '00017', 'date_echeance' => ''];
$tests = [];
foreach (['', '-1', '0', '1.5', '1e2', ' 1', '4294967296', '٠١', str_repeat('9', 100)] as $invalid) {
    $tests['ID refusé ' . $invalid] = fn() => check(uint($invalid) === null);
}
$tests['ID maximum unsigned'] = fn() => check(uint('4294967295') === 4294967295);
$tests['Matricule et contenu conservés'] = function () use ($values): void {
    $values['description'] = '  Faits à conserver  ';
    $data = validate_nc($values, '2026-10-05');
    check($data['responsable_matricule'] === '00017' && $data['description'] === $values['description'] && $data['date_echeance'] === null);
};
foreach (['description', 'traitement', 'responsable_matricule'] as $field) {
    foreach (['', " \t\n ", "\u{00A0}"] as $empty) {
        $tests["Champ vide $field " . bin2hex($empty)] = fn() => rejects(fn() => validate_nc([...$values, $field => $empty], '2026-10-05'));
    }
}
foreach (['description', 'traitement'] as $field) {
    $tests["Limite UTF-8 $field"] = function () use ($values, $field): void {
        $text = str_repeat('é', 32767) . 'a';
        check(validate_nc([...$values, $field => $text], '2026-10-05')[$field] === $text);
        rejects(fn() => validate_nc([...$values, $field => $text . 'a'], '2026-10-05'));
    };
}
$tests['Caractères sur quatre octets'] = function () use ($values): void {
    $text = str_repeat('📝', 16383) . 'abc';
    check(validate_nc([...$values, 'description' => $text], '2026-10-05')['description'] === $text);
    rejects(fn() => validate_nc([...$values, 'description' => $text . 'a'], '2026-10-05'));
};
$tests['Matricule de 60 caractères unicode'] = function () use ($values): void {
    check(validate_nc([...$values, 'responsable_matricule' => str_repeat('é', 60)], '2026-10-05')['responsable_matricule'] === str_repeat('é', 60));
    rejects(fn() => validate_nc([...$values, 'responsable_matricule' => str_repeat('é', 61)], '2026-10-05'));
};
foreach (['2026-10-04', '2026-02-30', '2026-1-01', '0001-01-01', 'not-a-date', "2026-10-05\0"] as $date) {
    $tests['Échéance refusée ' . bin2hex($date)] = fn() => rejects(fn() => validate_nc([...$values, 'date_echeance' => $date], '2026-10-05'));
}
foreach (['2026-10-05', '2026-10-06'] as $date) {
    $tests["Échéance valide $date"] = fn() => check(validate_nc([...$values, 'date_echeance' => $date], '2026-10-05')['date_echeance'] === $date);
}
$tests['Échéance obligatoire configurable'] = fn() => rejects(fn() => validate_nc($values, '2026-10-05', true));
$tests['password_verify Argon2id et bcrypt'] = function (): void {
    foreach ([PASSWORD_ARGON2ID, PASSWORD_BCRYPT] as $algorithm) {
        $hash = password_hash('Test-secret', $algorithm);
        check(verify_password($hash, 'Test-secret'));
        check(!verify_password($hash, 'incorrect'));
    }
    check(!verify_password(password_hash(str_repeat('a', 72), PASSWORD_BCRYPT), str_repeat('a', 73)));
    $bcrypt = password_hash('Test-secret', PASSWORD_BCRYPT);
    check(verify_password(str_replace('$2y$', '$2b$', $bcrypt), 'Test-secret'));
    check(verify_password(str_replace('$2y$', '$2a$', $bcrypt), 'Test-secret'));
};
foreach (['plaintext', '$argon2id$invalid', '$2y$invalid', '$argon2i$invalid'] as $hash) {
    $tests['Hash invalide ' . $hash] = fn() => check(!verify_password($hash, $hash));
}
$tests['Bcrypt refuse un suffixe après NUL sans troncature'] = function (): void {
    $hash = password_hash('Test-secret', PASSWORD_BCRYPT);
    check(!verify_password($hash, "Test-secret\0extra"));
};
foreach (['ADMINISTRATEUR', 'PILOTE_PROCESSUS', 'RESPONSABLE', 'CONSULTATION'] as $profile) {
    $tests['Profil refusé sans politique ' . $profile] = function () use ($user, $config, $profile): void {
        $closed = [...$config, 'permissions' => []];
        $actor = [...$user, 'profiles' => [$profile]];
        check(!can_create($actor, $closed) && scope_sql($actor, $closed) === ['0=1', []]);
    };
}
$tests['CONSULTATION sans création ni audit en démo'] = function () use ($user, $config): void {
    $actor = [...$user, 'profiles' => ['CONSULTATION']];
    check(!can_create($actor, $config) && scope_sql($actor, $config, 'audit') === ['0=1', []]);
};
$tests['Périmètres own/assigned/process/all et profil inconnu'] = function () use ($user, $config): void {
    [$sql, $args] = scope_sql($user, $config);
    check(str_contains($sql, 'enregistree_par_matricule = ?') && $args === ['00017', '00017']);
    [$sql, $args] = scope_sql([...$user, 'profiles' => ['PILOTE_PROCESSUS']], $config);
    check(str_contains($sql, 'processus_responsable') && $args === ['00017']);
    check(scope_sql([...$user, 'profiles' => ['ADMINISTRATEUR']], $config) === ['1=1', []]);
    check(scope_sql([...$user, 'profiles' => ['INCONNU']], $config) === ['0=1', []]);
};
$tests['Affectation limitée au processus choisi'] = function () use ($user, $config): void {
    $config['permissions']['RESPONSABLE']['assign'] = 'process';
    [$sql, $args] = assignment_sql($user, $config, 2);
    check(str_contains($sql, 'mine.processus_id=?') && $args === ['00017', 2]);
};
$tests['Politiques invalides refusées'] = function (): void {
    foreach ([['RESPONSABLE' => ['read' => ['service']]], ['RESPONSABLE' => ['create' => true]], ['RESPONSABLE' => ['create' => 'true']]] as $policy) {
        rejects(fn() => validate_policy($policy), RuntimeException::class);
    }
};
$tests['Clé signée et liée à l’acteur'] = function () use ($user, $config): void {
    $token = new_submission($user, $config);
    check(strlen(submission_key($token, '00017', $config)) === 64 && new_submission($user, $config) !== $token);
    rejects(fn() => submission_key($token, '17', $config), FormError::class, 400);
    rejects(fn() => submission_key($token . 'x', '00017', $config), FormError::class, 400);
};
$tests['CSRF et échappement HTML'] = function (): void {
    $_SESSION = [];
    $token = csrf_token();
    check_csrf(['csrf_token' => $token]);
    rejects(fn() => check_csrf(['csrf_token' => 'é']), HttpError::class, 400);
    check(e('<script>"&') === '&lt;script&gt;&quot;&amp;');
};
$tests['Champs répétés, tableaux et UTF-8 invalide refusés'] = function (): void {
    foreach (['description=a&description=b', 'description%5B%5D=a', 'description=%FF', 'description.x=a'] as $body) {
        rejects(fn() => decode_form($body), HttpError::class, 400);
    }
    check(decode_form('responsable_matricule=00017&description=Faits+%C3%A9') === ['responsable_matricule' => '00017', 'description' => 'Faits é']);
};
$tests['Pagination conserve les filtres'] = fn() => check(page_url(['reference' => 'NC-SI', 'statut_code' => 'OUVERTE'], 2) === '/non-conformites?reference=NC-SI&statut_code=OUVERTE&page=2');
$tests['Limites persistantes IP/identifiant et expiration'] = function (): void {
    $path = sys_get_temp_dir() . '/anrt-login-test-' . bin2hex(random_bytes(8));
    mkdir($path, 0700);
    $config = ['session_path' => $path];
    try {
        for ($i = 0; $i < 8; $i++) { check(allow_login($config, '00017', '127.0.0.1')); }
        check(!allow_login($config, '00017', '127.0.0.1'));
        for ($i = 0; $i < 22; $i++) { check(allow_login($config, 'other-' . $i, '127.0.0.1')); }
        check(!allow_login($config, 'new-identity', '127.0.0.1'));
        $entries = json_decode(file_get_contents($path . '/login-attempts.json'), true);
        foreach ($entries as &$entry) { $entry['until'] = 0; }
        unset($entry);
        file_put_contents($path . '/login-attempts.json', json_encode($entries));
        check(allow_login($config, '00017', '127.0.0.1'));
    } finally {
        if (is_file($path . '/login-attempts.json')) { unlink($path . '/login-attempts.json'); }
        rmdir($path);
    }
};
$tests['Connexion utilise password_verify et matricule'] = function (): void {
    $pdo = new FakeDatabase();
    check(authenticate($pdo, '00017', 'Test-secret')['matricule'] === '00017');
    check(authenticate($pdo, '00017', 'incorrect') === null);
    check(str_contains($pdo->calls[0][0], 'WHERE matricule=?') && $pdo->calls[0][1] === ['00017']);
};
$tests['SQL contrôle début, fin et profils'] = function (): void {
    $pdo = new FakeDatabase();
    check(load_user($pdo, '00017', true)['profiles'] === ['RESPONSABLE']);
    check(str_contains($pdo->calls[0][0], 'a.Date_Deb<=CURRENT_TIMESTAMP'));
    check(str_contains($pdo->calls[0][0], 'a.Date_Fin>CURRENT_TIMESTAMP') && str_contains($pdo->calls[0][0], 'FOR SHARE'));
    $pdo->accountValid = false;
    check(load_user($pdo, '00017') === null);
};
$tests['Création, une connexion, ordre et audit'] = function () use ($config, $user, $values): void {
    $pdo = new FakeDatabase();
    check(create_nc($pdo, $config, $user, $values, new_submission($user, $config), '127.0.0.1') === [41, false]);
    check($pdo->transactions === ['begin', 'commit']);
    check($pdo->insert[4] === '00017' && $pdo->insert[7] === '00017');
    $snapshot = json_decode($pdo->audit[2], true);
    check($snapshot['reference'] === 'NC-PO-001-26' && !isset($snapshot['passe']));
    check(str_contains(end($pdo->calls)[0], 'RELEASE_LOCK'));
};
foreach (['audit', 'compteur', 'compte', 'références', 'validation'] as $failure) {
    $tests['Rollback orchestré après erreur ' . $failure] = function () use ($config, $user, $values, $failure): void {
        $pdo = new FakeDatabase();
        if ($failure === 'audit') { $pdo->failAudit = true; }
        if ($failure === 'compteur') { $pdo->number = 1000; }
        if ($failure === 'compte') { $pdo->accountValid = false; }
        if ($failure === 'références') { $pdo->referencesValid = false; }
        if ($failure === 'validation') { $values['description'] = ''; }
        rejects(fn() => create_nc($pdo, $config, $user, $values, new_submission($user, $config), null),
            $failure === 'audit' ? PDOException::class : FormError::class);
        check($pdo->transactions === ['begin', 'rollback']);
        check(str_contains(end($pdo->calls)[0], 'RELEASE_LOCK'));
        if ($failure !== 'audit') { check($pdo->insert === null); }
    };
}
$tests['Double soumission et contenu différent'] = function () use ($config, $user, $values): void {
    $pdo = new FakeDatabase();
    $token = new_submission($user, $config);
    create_nc($pdo, $config, $user, $values, $token, null);
    $pdo->previous = ['entite_id' => '41', 'nouvelles_valeurs' => $pdo->audit[2]];
    $pdo->calls = $pdo->transactions = [];
    check(create_nc($pdo, $config, $user, $values, $token, null) === [41, true]);
    check($pdo->transactions === ['begin', 'rollback']);
    rejects(fn() => create_nc($pdo, $config, $user, [...$values, 'description' => 'autre'], $token, null), FormError::class, 409);
    check(!array_filter($pdo->calls, fn(array $c): bool => str_contains($c[0], 'CALL generer_reference')));
};
$tests['Réponse de commit perdue puis récupération instrumentée'] = function () use ($config, $user, $values): void {
    $pdo = new FakeDatabase();
    $pdo->loseCommitResponse = true;
    $token = new_submission($user, $config);
    rejects(fn() => create_nc($pdo, $config, $user, $values, $token, null), PDOException::class);
    $pdo->loseCommitResponse = false;
    $pdo->previous = ['entite_id' => '41', 'nouvelles_valeurs' => $pdo->audit[2]];
    check(create_nc($pdo, $config, $user, $values, $token, null) === [41, true]);
};
$tests['Refus métier avant transaction'] = function () use ($config, $user, $values): void {
    foreach ([['permissions' => []], ['initial_rules_confirmed' => false]] as $override) {
        $pdo = new FakeDatabase();
        rejects(fn() => create_nc($pdo, [...$config, ...$override], $user, $values, new_submission($user, $config), null), FormError::class, 403);
        check($pdo->calls === []);
    }
};

$failures = 0;
foreach ($tests as $name => $operation) {
    try { $operation(); echo '.'; } catch (Throwable $error) {
        $failures++;
        echo "\nECHEC $name : ", $error->getMessage(), "\n";
    }
}
echo "\n", count($tests) - $failures, ' tests PHP réussis, ', $failures, " échec(s).\n";
exit($failures === 0 ? 0 : 1);

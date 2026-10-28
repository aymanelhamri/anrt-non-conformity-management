<?php
declare(strict_types=1);

// Une NOUVELLE base anrt_recette_<aléatoire> est créée et conservée. Aucun dossier
// de la base locale de l'utilisateur n'est modifié. Fournir les accès de recette
// par TEST_MYSQL_HOST/PORT/USER/PASSWORD, jamais en argument de commande.
if (getenv('TEST_NC_SUIVI_MYSQL') !== '1') { echo "Suivi NC non exécuté : TEST_NC_SUIVI_MYSQL=1 requis.\n"; exit(0); }
require __DIR__ . '/mysql_support.php';
require __DIR__ . '/http_client.php';
putenv('TEST_SUBMISSION_SECRET=' . bin2hex(random_bytes(32)));
$process = null;
$folder = null;
$testAccount = null;
$exitCode = 0;
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
};
$counter = static function (string $html): int {
    preg_match('/class="notification-count[^\"]*"[^>]*>\(([0-9]+)\)<\/span>/', $html, $match);
    return isset($match[1]) ? (int) $match[1] : -1;
};
try {
    [$pdo, $config] = fresh_mysql_database();
    $root = dirname(__DIR__);
    query($pdo, "UPDATE acces_profil SET profil_code='ADMINISTRATEUR' WHERE matricule='00018'");
    $password = 'Recette-uniquement-2026';
    foreach (['validateur' => 'CONSULTATION', 'autre' => 'RESPONSABLE'] as $mat => $profile) {
        query($pdo, 'INSERT INTO pers (matricule, nom_prenom) VALUES (?,?)', [$mat, 'Compte ' . $mat]);
        query($pdo, 'INSERT INTO acces (matricule,passe) VALUES (?,?)', [$mat, password_hash($password, PASSWORD_ARGON2ID)]);
        query($pdo, 'INSERT INTO acces_profil VALUES (?,?)', [$mat, $profile]);
    }
    query($pdo, "INSERT INTO personnel_validation (matricule,nom_prenom,mat_val,nom_valideur,niv_val) VALUES ('00017','Recette 00017','validateur','Validateur RH','1')");
    $assignment = (int) $pdo->lastInsertId();
    // Le serveur HTTP utilise les mêmes droits limités que l'application locale,
    // pour repérer aussi les erreurs de privilèges masquées par un test en root.
    $testUser = 'anrt_test_' . bin2hex(random_bytes(8));
    $testPassword = bin2hex(random_bytes(32));
    $testAccount = $pdo->quote($testUser) . "@'127.0.0.1'";
    $pdo->exec('CREATE USER ' . $testAccount . ' IDENTIFIED BY ' . $pdo->quote($testPassword));
    $testDatabase = '`' . $config['mysql_database'] . '`';
    $pdo->exec('GRANT SELECT ON ' . $testDatabase . '.* TO ' . $testAccount);
    $pdo->exec('GRANT INSERT ON ' . $testDatabase . '.non_conformites TO ' . $testAccount);
    $pdo->exec('GRANT INSERT ON ' . $testDatabase . '.journal_audit TO ' . $testAccount);
    $pdo->exec('GRANT UPDATE (type_nc_id,nature_service_id,description,traitement,responsable_matricule,date_echeance) ON ' . $testDatabase . '.non_conformites TO ' . $testAccount);
    $pdo->exec('GRANT INSERT, UPDATE (lue_le) ON ' . $testDatabase . '.notifications TO ' . $testAccount);
    $pdo->exec('GRANT EXECUTE ON PROCEDURE ' . $testDatabase . '.generer_reference TO ' . $testAccount);
    $folder = sys_get_temp_dir() . '/anrt-nc-suivi-' . bin2hex(random_bytes(12));
    if (!mkdir($folder, 0700)) { throw new RuntimeException('Stockage de recette indisponible.'); }
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    if (!$listener) { throw new RuntimeException('Port de recette indisponible.'); }
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $environment = [...getenv(), 'MYSQL_HOST' => $config['mysql_host'], 'MYSQL_PORT' => (string) $config['mysql_port'],
        'MYSQL_DATABASE' => $config['mysql_database'], 'MYSQL_USER' => $testUser, 'MYSQL_PASSWORD' => $testPassword,
        'SECRET_KEY' => $config['secret'], 'APP_TIMEZONE' => 'UTC', 'DEMO_MODE' => 'true', 'SESSION_PATH' => $folder, 'SESSION_COOKIE_SECURE' => 'false'];
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/public/index.php'],
        [['pipe', 'r'], ['file', $folder . '/server.log', 'a'], ['file', $folder . '/server.log', 'a']], $pipes, $root, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Démarrage PHP impossible.'); }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (!proc_get_status($process)['running']) { break; }
        $probe = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    $check($ready, 'Serveur de recette démarré');
    $login = static function (string $mat) use ($port, $password, $check): array {
        $cookies = [];
        [, , $html] = browser_request($port, '/connexion', $cookies);
        [$status, $headers] = browser_request($port, '/connexion', $cookies, ['csrf_token' => browser_token($html), 'matricule' => $mat, 'password' => $password]);
        $expected = match ($mat) { 'validateur' => '/non-conformites', '00018' => '/administration', default => '/non-conformites/nouvelle' };
        preg_match('/^Location: ([^\r\n]+)/mi', $headers, $redirect);
        $check($status === 303 && ($redirect[1] ?? null) === $expected, 'Connexion et accueil adapté : ' . $mat);
        return $cookies;
    };
    $anonymous = [];
    foreach (['/non-conformites', '/non-conformites/1', '/non-conformites/1/modifier', '/notifications'] as $path) {
        [$status] = browser_request($port, $path, $anonymous);
        $check($status === 302, 'Consultation protégée sans session');
    }
    $owner = $login('00017');
    $validator = $login('validateur');
    $other = $login('autre');
    $admin = $login('00018');
    [, , $html] = browser_request($port, '/non-conformites/nouvelle', $owner);
    $payload = [...mysql_values(), 'csrf_token' => browser_token($html), 'submission_token' => browser_token($html, 'submission_token'), 'description' => '<script>constat</script> 📝'];
    [$status] = browser_request($port, '/non-conformites/nouvelle', $owner, $payload);
    $check($status === 303, 'Création HTTP');
    $nc = query($pdo, 'SELECT * FROM non_conformites ORDER BY id DESC LIMIT 1')->fetch();
    $id = (int) $nc['id'];
    $path = '/non-conformites/' . $id;
    $countNotifications = static fn(): int => (int) query($pdo, 'SELECT COUNT(*) FROM notifications')->fetchColumn();
    $check($countNotifications() === 3, 'Création : déclarant, validateur et admin, sans doublon');
    [$status] = browser_request($port, '/non-conformites/nouvelle', $owner, $payload);
    $check($status === 303 && $countNotifications() === 3, 'Réessai de création sans notification en double');
    foreach (['/non-conformites', '/non-conformites?statut=OUVERTE', $path] as $url) {
        [$status, , $html] = browser_request($port, $url, $owner);
        $check($status === 200 && str_contains($html, e($nc['reference'])) && $counter($html) === 1, 'Dossier personnel visible avec son statut et son compteur');
    }
    [$status, , $html] = browser_request($port, '/non-conformites?statut=CLOTUREE', $owner);
    $check($status === 200 && !str_contains($html, e($nc['reference'])), 'Filtre de statut réel');
    [$status, , $html] = browser_request($port, '/non-conformites', $other);
    $check($status === 200 && !str_contains($html, e($nc['reference'])), 'Dossier masqué au compte étranger');
    $check($counter($html) === 0, 'Le badge ne compte pas les notifications des autres comptes');
    foreach ([$path, $path . '/modifier'] as $url) {
        [$status] = browser_request($port, $url, $other);
        $check($status === 404, 'Identifiant deviné inaccessible');
    }
    foreach (['/non-conformites?matricule=00017', '/non-conformites?page%5B%5D=1', '/non-conformites?statut=INCONNU', '/notifications?matricule=00017'] as $url) {
        [$status] = browser_request($port, $url, $other);
        $check($status === 400, 'Filtre malformé ou changement de compte refusé');
    }
    [$status, , $html] = browser_request($port, $path, $validator);
    $check($status === 200 && str_contains($html, 'Valider cette version'), 'Validateur RH sans droit de création accède au dossier');
    $validatorVersion = browser_token($html, 'version_token');
    $validationPost = ['csrf_token' => browser_token($html), 'version_token' => $validatorVersion, 'assignment_id' => (string) $assignment];
    [$status, , $html] = browser_request($port, '/non-conformites', $validator);
    $check($status === 200 && str_contains($html, e($nc['reference'])), 'Liste accessible au validateur sans permission de création');
    [$status] = browser_request($port, '/non-conformites/nouvelle', $validator);
    $check($status === 403, 'Le validateur ne reçoit pas un droit de création');
    [$status] = browser_request($port, $path . '/modifier', $validator);
    $check($status === 403, 'La désignation RH ne donne pas le droit de modifier');
    [$status] = browser_request($port, $path . '/valider', $validator);
    $check($status === 405, 'Validation par GET refusée');
    [$status] = browser_request($port, $path . '/valider', $validator, ['assignment_id' => (string) $assignment]);
    $check($status === 400, 'Validation sans CSRF refusée');
    [, , $html] = browser_request($port, $path, $admin);
    [$status] = browser_request($port, $path . '/valider', $admin, ['csrf_token' => browser_token($html), 'assignment_id' => (string) $assignment,
        'version_token' => nc_version(nc_record($pdo, $id, load_user($pdo, '00018'), $config), load_user($pdo, '00018'), $config)]);
    $check($status === 403, 'Le profil administrateur ne remplace pas la désignation RH');
    [, , $html] = browser_request($port, $path . '/modifier', $owner);
    $edit = array_intersect_key($payload, array_flip(NC_EDIT_FIELDS));
    $edit['csrf_token'] = browser_token($html);
    $edit['version_token'] = browser_token($html, 'version_token');
    $check(!preg_match('/name="processus_id"/', $html) && str_contains($html, 'Enregistrer les modifications'), 'Formulaire prérempli, processus protégé');
    [$status] = browser_request($port, $path . '/modifier', $owner, [...$edit, 'description' => '']);
    $check($status === 422 && $countNotifications() === 3, 'Erreur de saisie sans notification');
    foreach (['statut_code' => 'CLOTUREE', 'processus_id' => '2', 'reference' => 'NC-FORGE', 'enregistree_par_matricule' => 'autre'] as $field => $value) {
        [$status] = browser_request($port, $path . '/modifier', $owner, [...$edit, $field => $value]);
        $check($status === 400, 'Champ protégé refusé : ' . $field);
    }
    [$status] = browser_request($port, $path . '/modifier', $owner, $edit);
    $check($status === 303 && $countNotifications() === 3, 'Aucune modification : aucun audit ni notification supplémentaire');
    $edit['description'] = 'Constat corrigé <script>alert(1)</script> 📝';
    $edit['type_nc_id'] = '2';
    $edit['nature_service_id'] = '2';
    [$status] = browser_request($port, $path . '/modifier', $owner, $edit);
    $check($status === 303 && $countNotifications() === 6, 'Modification et notifications');
    $after = query($pdo, 'SELECT * FROM non_conformites WHERE id=?', [$id])->fetch();
    $check($after['description'] === $edit['description'] && $after['reference'] === $nc['reference'] && $after['statut_code'] === 'OUVERTE', 'Contenu enregistré, référence et statut conservés');
    $audit = query($pdo, "SELECT anciennes_valeurs,nouvelles_valeurs FROM journal_audit WHERE operation='MODIFICATION' AND entite_id=?", [(string) $id])->fetch();
    $check(json_decode($audit['anciennes_valeurs'], true)['description'] === $nc['description'] && json_decode($audit['nouvelles_valeurs'], true)['description'] === $edit['description'], 'Anciennes et nouvelles valeurs dans l’audit');
    $check(json_decode($audit['anciennes_valeurs'], true)['type_libelle'] !== json_decode($audit['nouvelles_valeurs'], true)['type_libelle']
        && (int) $after['type_nc_id'] === 2 && (int) $after['nature_service_id'] === 2, 'Qualification modifiée et libellés conservés dans l’historique');
    [$status, , $html] = browser_request($port, $path, $owner);
    $check($status === 200 && str_contains($html, e($edit['description'])) && !str_contains($html, $edit['description']) && !str_contains($html, '_submission'), 'Historique échappé sans clés techniques');
    [$status, , $html] = browser_request($port, $path, $validator);
    $check($status === 200 && str_contains($html, 'À valider') && str_contains($html, 'Valider cette version'), 'Version modifiée encore en attente de validation');
    [$status] = browser_request($port, $path . '/valider', $validator, $validationPost);
    $check($status === 409 && $countNotifications() === 6, 'Une ancienne version ne peut pas être validée');
    [$status] = browser_request($port, $path . '/modifier', $owner, [...$edit, 'description' => 'Écrasement ancien']);
    $check($status === 409 && $countNotifications() === 6, 'Un formulaire périmé ne peut pas écraser une modification');
    [, , $html] = browser_request($port, $path . '/modifier', $owner);
    $edit['version_token'] = browser_token($html, 'version_token');
    // Simuler une panne de notifications : ni l'édition ni son audit ne doivent survivre.
    $pdo->exec("CREATE TRIGGER refuse_notification BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Panne recette'");
    [$status] = browser_request($port, $path . '/modifier', $owner, [...$edit, 'description' => 'Ne doit pas survivre']);
    $check($status === 503 && $countNotifications() === 6 && query($pdo, 'SELECT description FROM non_conformites WHERE id=?', [$id])->fetchColumn() === $edit['description'], 'Rollback modification lors d’une panne de notification');
    $check((int) query($pdo, "SELECT COUNT(*) FROM journal_audit WHERE operation='MODIFICATION'")->fetchColumn() === 1, 'Audit également annulé');
    $pdo->exec('DROP TRIGGER refuse_notification');
    // Le déclarant a encore un formulaire ouvert lorsque le validateur intervient.
    [, , $html] = browser_request($port, $path, $validator);
    $validationPost = ['csrf_token' => browser_token($html), 'version_token' => browser_token($html, 'version_token'), 'assignment_id' => (string) $assignment];
    [$status] = browser_request($port, $path . '/valider', $validator, $validationPost);
    $check($status === 303 && $countNotifications() === 9, 'Validation RH et notifications atomiques');
    [$status] = browser_request($port, $path . '/valider', $validator, $validationPost);
    $check($status === 303 && $countNotifications() === 9, 'Double validation sans doublon');
    $check(query($pdo, 'SELECT statut_code FROM non_conformites WHERE id=?', [$id])->fetchColumn() === 'OUVERTE', 'La validation ne clôture pas le dossier');
    foreach ([$owner, $admin] as $reader) {
        foreach ([$path, '/non-conformites'] as $url) {
            [$status, , $html] = browser_request($port, $url, $reader);
            $check($status === 200 && preg_match('/<button[^>]*disabled[^>]*>Modifier<\/button>/', $html) === 1
                && str_contains($html, 'Non-conformité validée : modification impossible.') && !str_contains($html, $path . '/modifier'), 'Bouton désactivé et message pour le déclarant et l’admin');
        }
        [$status] = browser_request($port, $path . '/modifier', $reader);
        $check($status === 403, 'Accès direct au formulaire validé refusé');
    }
    [$status] = browser_request($port, $path . '/modifier', $owner, [...$edit, 'description' => 'Ne doit pas remplacer le contenu validé']);
    $check($status === 403 && $countNotifications() === 9, 'Ancien formulaire bloqué après validation sans notification');
    try {
        update_nc($pdo, $config, load_user($pdo, '00017'), $id, [...array_intersect_key($edit, array_flip(NC_EDIT_FIELDS)), 'description' => 'Appel direct interdit'], $edit['version_token'], null);
        throw new RuntimeException('Une modification validée a été acceptée.');
    } catch (FormError $error) { $check($error->status === 403, 'Blocage aussi dans la fonction métier transactionnelle'); }
    $check(query($pdo, 'SELECT description FROM non_conformites WHERE id=?', [$id])->fetchColumn() === $edit['description']
        && (int) query($pdo, "SELECT COUNT(*) FROM journal_audit WHERE operation='MODIFICATION'")->fetchColumn() === 1, 'Contenu validé et audit inchangés après les tentatives');
    [$status, , $html] = browser_request($port, '/notifications', $owner);
    $check($status === 200 && str_contains($html, '3 notification(s) non lue(s)') && $counter($html) === 3, 'Boîte personnelle et badge synchronisés');
    $notification = query($pdo, "SELECT id FROM notifications WHERE destinataire_matricule='00017' ORDER BY id LIMIT 1")->fetchColumn();
    [, , $otherHtml] = browser_request($port, '/notifications', $other);
    [$status] = browser_request($port, '/notifications/' . $notification . '/lire', $other, ['csrf_token' => browser_token($otherHtml)]);
    $check($status === 404, 'Notification d’un autre compte inaccessible');
    [$status] = browser_request($port, '/notifications/' . $notification . '/lire', $owner, ['csrf_token' => browser_token($html)]);
    $check($status === 303 && query($pdo, 'SELECT lue_le FROM notifications WHERE id=?', [$notification])->fetchColumn() !== null, 'Marquage comme lue persistant');
    [$status, , $html] = browser_request($port, '/non-conformites', $owner);
    $check($status === 200 && $counter($html) === 2, 'Badge décrémenté après lecture, également dans la liste');
    [, , $adminHtml] = browser_request($port, '/administration', $admin);
    $check($counter($adminHtml) === 3, 'La lecture du déclarant ne change pas le badge administrateur');
    [$status] = browser_request($port, '/notifications/' . $notification . '/lire', $owner, ['csrf_token' => browser_token($html)]);
    [, , $html] = browser_request($port, '/notifications', $owner);
    $check($status === 303 && $counter($html) === 2, 'Relire une notification ne décrémente pas deux fois');
    foreach (query($pdo, "SELECT id FROM notifications WHERE destinataire_matricule='00017' AND lue_le IS NULL")->fetchAll() as $unread) {
        browser_request($port, '/notifications/' . $unread['id'] . '/lire', $owner, ['csrf_token' => browser_token($html)]);
    }
    [, , $html] = browser_request($port, $path, $owner);
    $check($counter($html) === 0, 'Badge revenu à zéro après lecture de toutes les notifications');
    [$status] = browser_request($port, '/notifications/' . $notification . '/lire', $owner);
    $check($status === 405, 'Lecture par GET sans mutation');
    // Fermer le dossier après l'ouverture du formulaire : le contrôle à l'écriture
    // doit résister à ce changement, y compris pour un compte administrateur.
    query($pdo, "UPDATE non_conformites SET statut_code='CLOTUREE',date_cloture=date_creation WHERE id=?", [$id]);
    foreach ([$owner, $admin] as $closedUser) {
        [$status, , $html] = browser_request($port, $path, $closedUser);
        $check($status === 200 && str_contains($html, 'Clôturée') && !str_contains($html, '/modifier'), 'Dossier clôturé consultable sans bouton de modification');
        [$status] = browser_request($port, $path . '/modifier', $closedUser);
        $check($status === 403, 'Formulaire fermé même pour l’administrateur');
    }
    [$status] = browser_request($port, $path . '/modifier', $owner, [...$edit, 'description' => 'Modification interdite']);
    $check($status === 403 && $countNotifications() === 9, 'POST forcé sur dossier clôturé refusé');
    [$status] = browser_request($port, $path . '/valider', $validator, $validationPost);
    $check($status === 403, 'Validation sur dossier clôturé refusée');
    // Un changement de statut ou de responsable n'efface pas la validation passée.
    query($pdo, "UPDATE non_conformites SET statut_code='AJOURNEE',date_cloture=NULL,responsable_matricule='autre' WHERE id=?", [$id]);
    [$status, , $html] = browser_request($port, $path . '/modifier', $other);
    $check($status === 403, 'Une validation passée continue de bloquer après réaffectation');
    // Les autres dossiers non validés restent modifiables par leur responsable.
    [$otherId] = create_nc($pdo, $config, mysql_user(), mysql_values(), new_submission(mysql_user(), $config), null);
    query($pdo, "UPDATE non_conformites SET statut_code='AJOURNEE',responsable_matricule='autre' WHERE id=?", [$otherId]);
    [$status, , $html] = browser_request($port, '/non-conformites/' . $otherId . '/modifier', $other);
    $check($status === 200 && str_contains($html, 'Ajournée'), 'Responsable affecté autorisé sur un dossier non validé');
    query($pdo, "UPDATE non_conformites SET statut_code='ANNULEE' WHERE id=?", [$otherId]);
    [$status] = browser_request($port, '/non-conformites/' . $otherId . '/modifier', $other);
    $check($status === 403, 'Dossier annulé également protégé');
    query($pdo, "UPDATE acces SET Date_Fin=CURRENT_TIMESTAMP WHERE matricule='00017'");
    [$status] = browser_request($port, '/notifications', $owner);
    $check($status === 302, 'Compte révoqué exclu des notifications');
    echo "$checks contrôles du suivi NC avec MySQL et HTTP réels réussis.\n";
} catch (Throwable $error) {
    // Ne jamais imprimer de DSN, SQL, environnement ou mot de passe.
    fwrite(STDERR, ($error instanceof PDOException ? 'Erreur MySQL de recette (code ' . $error->getCode() . ')' : $error->getMessage()) . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($testAccount !== null) { $pdo->exec('DROP USER ' . $testAccount); }
    if ($folder !== null && is_dir($folder)) {
        foreach (scandir($folder) as $name) { if ($name !== '.' && $name !== '..' && is_file($folder . '/' . $name)) { unlink($folder . '/' . $name); } }
        rmdir($folder);
    }
}
exit($exitCode);

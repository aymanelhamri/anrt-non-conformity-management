<?php
declare(strict_types=1);

/*
 * Simulation du parcours complet sur le site local et la vraie base MySQL.
 * Chaque exécution réussie conserve une NC fictive et son audit pour inspection.
 * Les comptes et les autres déclarations existantes ne sont pas modifiés.
 * Activation dans le terminal : TEST_SIMULATION_MYSQL=1.
 */
if (PHP_SAPI !== 'cli' || getenv('TEST_SIMULATION_MYSQL') !== '1') {
    echo "Simulation non exécutée : activation explicite TEST_SIMULATION_MYSQL=1 requise.\n";
    exit(0);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

class SimulationFailure extends RuntimeException {}

// Envoyer les mêmes requêtes HTTP qu'un navigateur, sans suivre automatiquement
// les redirections. Cela permet de vérifier chaque étape et chaque code HTTP.
function simulation_http(string $path, array &$cookies, ?array $post = null): array
{
    $socket = @stream_socket_client('tcp://127.0.0.1:5000', $code, $message, 5);
    if (!$socket) { throw new SimulationFailure('Le site local sur le port 5000 est indisponible.'); }
    stream_set_timeout($socket, 15);
    $body = $post === null ? '' : http_build_query($post);
    $headers = ['Host' => '127.0.0.1:5000', 'Connection' => 'close'];
    if ($cookies) { $headers['Cookie'] = http_build_query($cookies, '', '; '); }
    if ($post !== null) {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $headers['Content-Length'] = (string) strlen($body);
    }
    $request = ($post === null ? 'GET' : 'POST') . " $path HTTP/1.1\r\n";
    foreach ($headers as $name => $value) { $request .= "$name: $value\r\n"; }
    $request .= "\r\n" . $body;
    try {
        $offset = 0;
        while ($offset < strlen($request)) {
            $written = fwrite($socket, substr($request, $offset));
            if ($written === false || $written === 0) { throw new SimulationFailure('Envoi HTTP incomplet.'); }
            $offset += $written;
        }
        $raw = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);
    } finally {
        fclose($socket);
    }
    if ($metadata['timed_out'] || $raw === false || !str_contains($raw, "\r\n\r\n")) {
        throw new SimulationFailure('Réponse HTTP incomplète.');
    }
    [$head, $html] = explode("\r\n\r\n", $raw, 2);
    if (!preg_match('#^HTTP/1\.[01] ([0-9]{3})#', $head, $status)) {
        throw new SimulationFailure('Statut HTTP invalide.');
    }
    preg_match_all('/^Set-Cookie: anrt_session=([^;]*)/mi', $head, $matches);
    foreach ($matches[1] as $cookie) {
        if ($cookie === '' || $cookie === 'deleted') { unset($cookies['anrt_session']); }
        else { $cookies['anrt_session'] = $cookie; }
    }
    return [(int) $status[1], $head, $html];
}

function simulation_token(string $html, string $name): string
{
    if (!preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $match)) {
        throw new SimulationFailure('Champ caché absent : ' . $name);
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

// Vérifier les NC et leurs audits de création, indépendamment des événements
// de connexion qui sont désormais journalisés dans la même table.
function simulation_counts(PDO $pdo, int $processId, string $today): array
{
    return [
        'non_conformites' => (int) query($pdo, 'SELECT COUNT(*) FROM non_conformites')->fetchColumn(),
        'audits' => (int) query($pdo, "SELECT COUNT(*) FROM journal_audit WHERE entite='non_conformites' AND operation='CREATION'")->fetchColumn(),
        'compteur' => (int) query($pdo, "SELECT derniere_valeur FROM compteurs_reference
            WHERE type_document='NC' AND processus_id=? AND annee=?", [$processId, (int) substr($today, 0, 4)])->fetchColumn(),
    ];
}

$createdReference = null;
try {
    $root = dirname(__DIR__);
    $config = bootstrap();
    if ($config['mysql_host'] !== '127.0.0.1' || (int) $config['mysql_port'] !== 3307
        || $config['mysql_database'] !== 'anrt_auth_local' || !$config['demo']) {
        throw new SimulationFailure('Simulation réservée à anrt_auth_local sur 127.0.0.1:3307 en mode démo.');
    }
    $pdo = database($config);
    $today = business_today($config);
    $process = query($pdo, "SELECT id, code, nom FROM processus WHERE code='SI' AND actif=TRUE")->fetch();
    $type = query($pdo, 'SELECT id, libelle FROM types_nc WHERE actif=TRUE ORDER BY id LIMIT 1')->fetch();
    $nature = query($pdo, 'SELECT id, libelle FROM natures_service WHERE actif=TRUE ORDER BY id LIMIT 1')->fetch();
    if (!$process || !$type || !$nature) { throw new SimulationFailure('Référentiels de démonstration absents.'); }

    // Utiliser le compte d'Amine conservé dans compteDemo, sans recopier son mot
    // de passe dans le test ou dans le rapport de simulation.
    $accountFile = file_get_contents($root . '/compteDemo');
    if (!preg_match('/Nom : ([^\r\n]+)\RMatricule : 00020\RMot de passe : ([^\r\n]+)/u', $accountFile, $account)) {
        throw new SimulationFailure('Compte 00020 introuvable dans compteDemo.');
    }
    $matricule = '00020';
    $password = $account[2];
    $before = simulation_counts($pdo, (int) $process['id'], $today);
    $checks = [];
    $check = static function (bool $condition, string $label) use (&$checks): void {
        if (!$condition) { throw new SimulationFailure($label . ' : échec.'); }
        $checks[] = $label;
        echo 'OK - ', $label, PHP_EOL;
    };

    $cookies = [];
    [$status, $headers] = simulation_http('/non-conformites/nouvelle', $cookies);
    $check($status === 302 && str_contains($headers, 'Location: /connexion'), 'Accès au formulaire refusé sans connexion');
    [$status, , $html] = simulation_http('/connexion', $cookies);
    $check($status === 200, 'Affichage de la page de connexion');
    $loginCsrf = simulation_token($html, 'csrf_token');
    [$status] = simulation_http('/connexion', $cookies, ['matricule' => $matricule, 'password' => $password]);
    $check($status === 400, 'Connexion sans jeton CSRF refusée');
    [$status] = simulation_http('/connexion', $cookies, ['csrf_token' => $loginCsrf, 'matricule' => $matricule, 'password' => 'incorrect']);
    $check($status === 401, 'Mauvais mot de passe refusé');
    $anonymousSession = $cookies['anrt_session'] ?? null;
    [$status, $headers] = simulation_http('/connexion', $cookies, ['csrf_token' => $loginCsrf, 'matricule' => $matricule, 'password' => $password]);
    $check($status === 303 && str_contains($headers, 'Location: /non-conformites/nouvelle'), 'Connexion du compte 00020 acceptée');
    $check(isset($cookies['anrt_session']) && $cookies['anrt_session'] !== $anonymousSession, 'Identifiant de session renouvelé');
    [$status, $headers] = simulation_http('/non-conformites/confirmation', $cookies);
    $check($status === 303 && str_contains($headers, 'Location: /non-conformites/nouvelle'), 'Aucune confirmation avant création');
    [$status, , $html] = simulation_http('/non-conformites/nouvelle', $cookies);
    $check($status === 200 && str_contains($html, e($account[1])), 'Formulaire affiché avec identité issue de MySQL');

    // Cette marque distingue la déclaration fictive de toutes les autres données.
    $marker = 'SIM-' . bin2hex(random_bytes(8));
    $values = [
        'csrf_token' => simulation_token($html, 'csrf_token'),
        'submission_token' => simulation_token($html, 'submission_token'),
        'processus_id' => (string) $process['id'], 'type_nc_id' => (string) $type['id'],
        'nature_service_id' => (string) $nature['id'],
        'description' => "[SIMULATION $marker] Retard fictif dans le traitement d'une demande d'assignation de fréquences.",
        'traitement' => 'Vérifier le dossier fictif, compléter les informations et planifier son traitement. Aucune donnée réelle.',
        'responsable_matricule' => $matricule,
        'date_echeance' => (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d'),
    ];

    // Vérifier les refus avant de créer la déclaration : aucun compteur ne doit avancer.
    [$status, , $invalidHtml] = simulation_http('/non-conformites/nouvelle', $cookies, [...$values, 'description' => '']);
    $check($status === 422 && str_contains($invalidHtml, e($values['traitement'])), 'Champ obligatoire vide refusé et saisie conservée');
    [$status] = simulation_http('/non-conformites/nouvelle', $cookies, [...$values, 'date_echeance' => (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d')]);
    $check($status === 422, 'Échéance antérieure à la création refusée');
    [$status] = simulation_http('/non-conformites/nouvelle', $cookies, [...$values, 'type_nc_id' => '4294967295']);
    $check($status === 422, 'Référentiel inexistant refusé');
    [$status] = simulation_http('/non-conformites/nouvelle', $cookies, [...$values, 'responsable_matricule' => '00021']);
    $check($status === 422, 'Affectation à un autre responsable refusée');
    [$status] = simulation_http('/non-conformites/nouvelle', $cookies, [...$values, 'statut_code' => 'CLOTUREE']);
    $check($status === 400, 'Modification du statut imposé par le serveur refusée');
    $withoutCsrf = $values;
    unset($withoutCsrf['csrf_token']);
    [$status] = simulation_http('/non-conformites/nouvelle', $cookies, $withoutCsrf);
    $check($status === 400, 'Déclaration sans jeton CSRF refusée');
    $check(simulation_counts($pdo, (int) $process['id'], $today) === $before, 'Aucune NC, aucun audit de création ni compteur ajouté par les saisies refusées');

    // Envoyer réellement le formulaire ; le site utilise son propre compte MySQL.
    [$status, $headers] = simulation_http('/non-conformites/nouvelle', $cookies, $values);
    $check($status === 303 && str_contains($headers, 'Location: /non-conformites/confirmation'), 'Enregistrement MySQL accepté et redirection vers confirmation');
    $rows = query($pdo, 'SELECT * FROM non_conformites WHERE description=?', [$values['description']])->fetchAll();
    $check(count($rows) === 1, 'Une seule déclaration retrouvée dans MySQL');
    $row = $rows[0];
    $createdReference = $row['reference'];
    [$status, , $confirmationHtml] = simulation_http('/non-conformites/confirmation', $cookies);
    $check($status === 200 && str_contains($confirmationHtml, e($createdReference)), 'Référence enregistrée affichée dans la confirmation');
    $check($row['enregistree_par_matricule'] === $matricule && $row['responsable_matricule'] === $matricule
        && $row['date_creation'] === $today && $row['statut_code'] === 'OUVERTE', 'Déclarant, responsable, date et statut stockés correctement');
    $check((int) $row['processus_id'] === (int) $process['id'] && (int) $row['type_nc_id'] === (int) $type['id']
        && (int) $row['nature_service_id'] === (int) $nature['id'] && $row['traitement'] === $values['traitement']
        && $row['date_echeance'] === $values['date_echeance'], 'Qualification, texte accentué et échéance conservés dans MySQL');
    $audits = query($pdo, "SELECT id, acteur_matricule, nouvelles_valeurs FROM journal_audit
        WHERE entite='non_conformites' AND entite_id=? AND operation='CREATION'", [(string) $row['id']])->fetchAll();
    $check(count($audits) === 1 && $audits[0]['acteur_matricule'] === $matricule, 'Un audit de création associé au compte connecté');
    $snapshot = json_decode($audits[0]['nouvelles_valeurs'], true, 512, JSON_THROW_ON_ERROR);
    $check($snapshot['reference'] === $createdReference && $snapshot['description'] === $values['description'], 'Audit contenant la référence et les informations enregistrées');
    $expected = ['non_conformites' => $before['non_conformites'] + 1, 'audits' => $before['audits'] + 1, 'compteur' => $before['compteur'] + 1];
    $check(simulation_counts($pdo, (int) $process['id'], $today) === $expected, 'Une NC, un audit et un seul numéro de référence ajoutés');

    // Rejouer exactement le même POST pour simuler un double envoi ou une réponse perdue.
    [$status, $headers] = simulation_http('/non-conformites/nouvelle', $cookies, $values);
    $check($status === 303 && str_contains($headers, 'Location: /non-conformites/confirmation'), 'Renvoi du même formulaire accepté');
    [$status, , $replayHtml] = simulation_http('/non-conformites/confirmation', $cookies);
    $check($status === 200 && str_contains($replayHtml, 'Enregistrement retrouvé') && str_contains($replayHtml, e($createdReference)), 'Réessai retrouvant la même référence');
    $check(simulation_counts($pdo, (int) $process['id'], $today) === $expected, 'Aucun doublon ni nouveau compteur après réessai');
    [$status] = simulation_http('/non-conformites/nouvelle', $cookies, [...$values, 'description' => $values['description'] . ' Contenu modifié.']);
    $check($status === 409 && simulation_counts($pdo, (int) $process['id'], $today) === $expected, 'Même clé avec un contenu différent refusée sans nouvelle écriture');

    [$status, , $freshHtml] = simulation_http('/non-conformites/nouvelle', $cookies);
    $check($status === 200 && simulation_token($freshHtml, 'submission_token') !== $values['submission_token']
        && !str_contains($freshHtml, $marker), 'Nouvelle déclaration vide avec une nouvelle clé');
    [$status, $headers] = simulation_http('/non-conformites/confirmation', $cookies);
    $check($status === 303 && str_contains($headers, 'Location: /non-conformites/nouvelle'), 'Ancienne confirmation retirée après ouverture d’un nouveau formulaire');
    $oldSession = $cookies['anrt_session'];
    [$status, $headers] = simulation_http('/deconnexion', $cookies, ['csrf_token' => simulation_token($freshHtml, 'csrf_token')]);
    $check($status === 303 && str_contains($headers, 'Location: /connexion') && !isset($cookies['anrt_session']), 'Déconnexion et suppression du cookie');
    $revoked = ['anrt_session' => $oldSession];
    [$status, $headers] = simulation_http('/non-conformites/nouvelle', $revoked);
    $check($status === 302 && str_contains($headers, 'Location: /connexion'), 'Ancien cookie inutilisable après déconnexion');

    // Conserver les résultats lisibles sans mot de passe, cookie ou jeton de formulaire.
    $report = [
        'date_metier' => $today, 'base' => $config['mysql_database'], 'compte' => $matricule,
        'nom' => $account[1], 'id' => (int) $row['id'], 'reference' => $createdReference,
        'processus' => $process['nom'], 'type_nc' => $type['libelle'], 'nature_service' => $nature['libelle'],
        'description' => $row['description'], 'traitement' => $row['traitement'],
        'date_echeance' => $row['date_echeance'], 'statut' => $row['statut_code'],
        'audit_id' => (int) $audits[0]['id'], 'avant' => $before, 'apres' => $expected,
        'nombre_controles' => count($checks), 'controles' => $checks,
    ];
    if (file_put_contents($root . '/instance/simulation-complete.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
        throw new SimulationFailure('Écriture du rapport local impossible.');
    }
    echo count($checks), ' contrôles réussis. NC conservée : ', $createdReference, ' (id ', $row['id'], ').', PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof SimulationFailure ? $error->getMessage()
        : 'Simulation interrompue (' . get_class($error) . ').') . PHP_EOL);
    if ($createdReference !== null) { fwrite(STDERR, 'La NC déjà créée est conservée : ' . $createdReference . PHP_EOL); }
    exit(1);
}

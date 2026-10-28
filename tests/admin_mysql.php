<?php
declare(strict_types=1);

// Recette réelle, volontaire : elle lit les comptes et NC existants et produit
// des événements de connexion dans le journal. Aucun compte ou NC n'est créé.
if (getenv('TEST_ADMIN_MYSQL') !== '1') {
    echo "Administration MySQL non exécutée : TEST_ADMIN_MYSQL=1 requis.\n";
    exit(0);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
class AdminTestFailure extends RuntimeException {}

function admin_test_http(int $port, string $path, array &$cookies, ?array $post = null): array
{
    $socket = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 5);
    if (!$socket) { throw new AdminTestFailure('Serveur de recette inaccessible.'); }
    stream_set_timeout($socket, 15);
    $body = $post === null ? '' : http_build_query($post);
    $request = ($post === null ? 'GET' : 'POST') . " $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n";
    if ($cookies) { $request .= 'Cookie: ' . http_build_query($cookies, '', '; ') . "\r\n"; }
    if ($post !== null) { $request .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n"; }
    $request .= "\r\n" . $body;
    try {
        $offset = 0;
        while ($offset < strlen($request)) {
            $written = fwrite($socket, substr($request, $offset));
            if (!$written) { throw new AdminTestFailure('Envoi HTTP incomplet.'); }
            $offset += $written;
        }
        $raw = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);
    } finally { fclose($socket); }
    if ($raw === false || $metadata['timed_out'] || !str_contains($raw, "\r\n\r\n")) { throw new AdminTestFailure('Réponse HTTP incomplète.'); }
    [$headers, $html] = explode("\r\n\r\n", $raw, 2);
    if (!preg_match('#^HTTP/1\.[01] ([0-9]+)#', $headers, $status)) { throw new AdminTestFailure('Statut HTTP absent.'); }
    preg_match_all('/^Set-Cookie: anrt_session=([^;]*)/mi', $headers, $matches);
    foreach ($matches[1] as $cookie) {
        if ($cookie === '' || $cookie === 'deleted') { unset($cookies['anrt_session']); }
        else { $cookies['anrt_session'] = $cookie; }
    }
    return [(int) $status[1], $headers, $html];
}

function admin_test_csrf(string $html): string
{
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match)) { throw new AdminTestFailure('CSRF absent.'); }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function admin_test_rows(string $html): string
{
    if (!preg_match('#<tbody>(.*?)</tbody>#s', $html, $match)) { throw new AdminTestFailure('Tableau absent.'); }
    return $match[1];
}

$process = null;
$folder = null;
$exitCode = 0;
try {
    $config = bootstrap();
    if ($config['mysql_host'] !== '127.0.0.1' || (int) $config['mysql_port'] !== 3307
        || $config['mysql_database'] !== 'anrt_auth_local' || !$config['demo']) {
        throw new AdminTestFailure('Recette réservée à la base locale de démonstration.');
    }
    $root = dirname(__DIR__);
    $pdo = database($config);
    $source = file_get_contents($root . '/compteDemo');
    $credentials = [];
    preg_match_all('/Matricule : ([0-9]+)\RMot de passe : ([^\r\n]+)/u', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) { $credentials[$match[1]] = $match[2]; }
    foreach (['00001', '00020', '00021'] as $id) {
        if (!isset($credentials[$id])) { throw new AdminTestFailure('Compte de recette absent de compteDemo.'); }
    }
    $beforeNc = (int) query($pdo, 'SELECT COUNT(*) FROM non_conformites')->fetchColumn();
    $recentNc = query($pdo, 'SELECT id, reference, description, enregistree_par_matricule FROM non_conformites ORDER BY id DESC LIMIT 2')->fetchAll();
    $auditStart = (int) query($pdo, 'SELECT COALESCE(MAX(id),0) FROM journal_audit')->fetchColumn();
    $checks = 0;
    $check = static function (bool $condition, string $label) use (&$checks): void {
        if (!$condition) { throw new AdminTestFailure($label . ' : échec.'); }
        $checks++;
    };

    // Isoler les sessions et les limites de tentatives de l'application ouverte sur 5000.
    $folder = sys_get_temp_dir() . '/anrt-admin-' . bin2hex(random_bytes(12));
    if (!mkdir($folder, 0700)) { throw new AdminTestFailure('Stockage de recette indisponible.'); }
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    if (!$listener) { throw new AdminTestFailure('Port de recette indisponible.'); }
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $environment = [...getenv(), 'SESSION_PATH' => $folder, 'SESSION_COOKIE_SECURE' => 'false'];
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/public/index.php'],
        [['pipe', 'r'], ['file', $folder . '/server.log', 'a'], ['file', $folder . '/server.log', 'a']], $pipes, $root, $environment);
    if (!is_resource($process)) { throw new AdminTestFailure('Démarrage PHP impossible.'); }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (!proc_get_status($process)['running']) { break; }
        $probe = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 0.1);
        if ($probe) { fclose($probe); $ready = proc_get_status($process)['running']; break; }
        usleep(100000);
    }
    if (!$ready) { throw new AdminTestFailure('Le serveur de recette ne répond pas.'); }

    $cookies = [];
    foreach (['/administration', '/administration/comptes', '/administration/non-conformites', '/administration/journal'] as $path) {
        [$status, $headers] = admin_test_http($port, $path, $cookies);
        $check($status === 302 && str_contains($headers, 'Location: /connexion'), 'Administration protégée sans session');
    }
    // Créer de vrais événements pour deux comptes afin de vérifier leur séparation.
    foreach (['00020', '00021'] as $matricule) {
        [, , $html] = admin_test_http($port, '/connexion', $cookies);
        if ($matricule === '00020') {
            [$status] = admin_test_http($port, '/connexion', $cookies, ['csrf_token' => admin_test_csrf($html), 'matricule' => $matricule, 'password' => 'incorrect']);
            $check($status === 401, 'Tentative refusée enregistrable');
        }
        [$status, $headers] = admin_test_http($port, '/connexion', $cookies,
            ['csrf_token' => admin_test_csrf($html), 'matricule' => $matricule, 'password' => $credentials[$matricule]]);
        $check($status === 303 && str_contains($headers, 'Location: /non-conformites/nouvelle'), 'Connexion responsable inchangée');
        if ($matricule === '00020') {
            foreach (['/administration', '/administration/comptes', '/administration/non-conformites', '/administration/non-conformites/1', '/administration/journal?matricule=00001'] as $path) {
                [$status] = admin_test_http($port, $path, $cookies);
                $check($status === 403, 'Compte responsable exclu des lectures globales');
            }
        }
        [, , $html] = admin_test_http($port, '/non-conformites/nouvelle', $cookies);
        $check(!str_contains($html, 'href="/administration"'), 'Menu administrateur absent pour un responsable');
        [$status] = admin_test_http($port, '/deconnexion', $cookies, ['csrf_token' => admin_test_csrf($html)]);
        $check($status === 303, 'Déconnexion responsable acceptée');
    }

    $unknown = 'inconnu-<script>alert(1)</script>';
    [, , $html] = admin_test_http($port, '/connexion', $cookies);
    [$status] = admin_test_http($port, '/connexion', $cookies, ['csrf_token' => admin_test_csrf($html), 'matricule' => $unknown, 'password' => 'incorrect']);
    $check($status === 401, 'Matricule inconnu refusé sans erreur de clé étrangère');
    [, , $html] = admin_test_http($port, '/connexion', $cookies);
    [$status, $headers] = admin_test_http($port, '/connexion', $cookies,
        ['csrf_token' => admin_test_csrf($html), 'matricule' => '00001', 'password' => $credentials['00001']]);
    $check($status === 303 && preg_match('#^Location: /administration\r?$#mi', $headers) === 1, 'Administrateur dirigé vers son espace');

    $pages = [];
    foreach (['/administration', '/administration/comptes', '/administration/non-conformites', '/administration/journal'] as $path) {
        [$status, , $html] = admin_test_http($port, $path, $cookies);
        $check($status === 200 && str_contains($html, 'Admin Demo'), 'Page ' . $path . ' accessible avec identité MySQL (HTTP ' . $status . ')');
        $pages[$path] = $html;
    }
    $accountRows = admin_test_rows($pages['/administration/comptes']);
    foreach (['00001', '00017', '00018', '00019', '00020', '00021', '00022'] as $matricule) {
        $check(str_contains($accountRows, $matricule), 'Compte présent dans la liste globale');
    }
    foreach (['/administration', '/administration/comptes', '/administration/non-conformites', '/administration/journal'] as $path) {
        [$status] = admin_test_http($port, $path, $cookies, ['csrf_token' => admin_test_csrf($pages['/administration'])]);
        $check($status === 405, 'Consultation administrative sans action POST');
    }
    [$status, , $filteredHtml] = admin_test_http($port, '/administration/non-conformites?matricule=00020', $cookies);
    $expectedNc = query($pdo, 'SELECT reference FROM non_conformites WHERE enregistree_par_matricule=? ORDER BY id DESC LIMIT 20', ['00020'])->fetchAll(PDO::FETCH_COLUMN);
    $check($status === 200 && count($expectedNc) > 0 && count(array_filter($expectedNc,
        static fn(string $reference): bool => str_contains(admin_test_rows($filteredHtml), e($reference)))) === count($expectedNc), 'Déclarations filtrées par leur compte déclarant');
    foreach ($recentNc as $nc) {
        $check(str_contains(admin_test_rows($pages['/administration/non-conformites']), e($nc['reference'])), 'Déclaration d’un autre compte visible');
        [$status, , $html] = admin_test_http($port, '/administration/non-conformites/' . $nc['id'], $cookies);
        $check($status === 200 && str_contains($html, e($nc['description'])), 'Détail complet de la déclaration lisible');
        [$status, , $html] = admin_test_http($port, '/administration/journal?nc_id=' . $nc['id'], $cookies);
        $check($status === 200 && str_contains(admin_test_rows($html), e($nc['reference'])), 'Audit historique de la déclaration conservé');
    }

    [$status, , $html] = admin_test_http($port, '/administration/journal?matricule=00020', $cookies);
    $rows = admin_test_rows($html);
    $check($status === 200 && str_contains($rows, 'Connexion réussie') && str_contains($rows, 'Connexion refusée') && str_contains($rows, 'Déconnexion'), 'Événements du compte sélectionné visibles');
    $check(!str_contains($rows, 'Sara Demo'), 'Journal filtré sans les événements d’un autre compte');
    [$status, , $html] = admin_test_http($port, '/administration/journal?matricule=00020&operation=CONNEXION_REFUSEE', $cookies);
    $check($status === 200 && str_contains(admin_test_rows($html), 'Connexion refusée') && !str_contains(admin_test_rows($html), 'Connexion réussie'), 'Filtre par opération appliqué');
    [$status, , $html] = admin_test_http($port, '/administration/journal?' . http_build_query(['matricule' => $unknown]), $cookies);
    $check($status === 200 && str_contains($html, e($unknown)) && !str_contains($html, $unknown), 'Contenu malveillant échappé dans le journal');
    [$status, , $html] = admin_test_http($port, '/administration/journal?' . http_build_query(['matricule' => "' OR 1=1 --"]), $cookies);
    $check($status === 200 && str_contains(admin_test_rows($html), 'Aucun événement'), 'Filtre de matricule traité comme une valeur SQL');
    foreach (['/administration/journal?page=0', '/administration/journal?page%5B%5D=1', '/administration/journal?operation=inconnue'] as $path) {
        [$status] = admin_test_http($port, $path, $cookies);
        $check($status === 400, 'Filtre malformé refusé');
    }
    [$status] = admin_test_http($port, '/administration/journal?page=999999', $cookies);
    $check($status === 200, 'Pagination hors limite ramenée à la dernière page');
    [$status] = admin_test_http($port, '/administration/non-conformites/4294967295', $cookies);
    $check($status === 404, 'Déclaration inexistante refusée');
    $combined = implode('', $pages);
    $check(!str_contains($combined, '$argon2id$') && !str_contains($combined, '_submission')
        && !str_contains($combined, $config['secret']) && !str_contains($combined, $config['mysql_password']), 'Aucun hash, secret ou clé de soumission affiché');

    // Les trois types d'événements doivent être réellement persistés pour Amine.
    $events = query($pdo, "SELECT operation, nouvelles_valeurs FROM journal_audit
        WHERE id>? AND acteur_matricule=? AND entite='acces'", [$auditStart, '00020'])->fetchAll();
    $check(count($events) === 3 && array_diff(['CONNEXION', 'CONNEXION_REFUSEE', 'DECONNEXION'], array_column($events, 'operation')) === [], 'Trois événements distincts persistés pour le compte responsable');
    foreach ($events as $event) {
        $details = json_decode($event['nouvelles_valeurs'], true, 512, JSON_THROW_ON_ERROR);
        $check(array_diff(array_keys($details), ['matricule', 'motif']) === [] && !str_contains($event['nouvelles_valeurs'], $credentials['00020']), 'Journal MySQL sans mot de passe ni cookie');
    }
    $oldCookie = $cookies['anrt_session'];
    [, , $html] = admin_test_http($port, '/administration', $cookies);
    [$status] = admin_test_http($port, '/deconnexion', $cookies, ['csrf_token' => admin_test_csrf($html)]);
    $check($status === 303 && !isset($cookies['anrt_session']), 'Déconnexion administrateur effective');
    $revoked = ['anrt_session' => $oldCookie];
    [$status, $headers] = admin_test_http($port, '/administration/journal', $revoked);
    $check($status === 302 && str_contains($headers, 'Location: /connexion'), 'Journal inaccessible avec un cookie administrateur révoqué');
    $check((int) query($pdo, 'SELECT COUNT(*) FROM non_conformites')->fetchColumn() === $beforeNc, 'Aucune déclaration créée ou modifiée par la recette');
    echo "$checks contrôles d'administration avec MySQL et HTTP réels réussis.\n";
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof AdminTestFailure ? $error->getMessage() : 'Recette administrateur interrompue (' . get_class($error) . ').') . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($folder !== null && is_dir($folder)) {
        foreach (scandir($folder) as $name) {
            if ($name !== '.' && $name !== '..' && is_file($folder . '/' . $name)) { unlink($folder . '/' . $name); }
        }
        rmdir($folder);
    }
}
exit($exitCode);

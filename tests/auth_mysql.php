<?php
declare(strict_types=1);

/*
 * Vérifie la connexion avec les comptes fictifs créés par bin/setup-local.php.
 * Les lectures passent par un vrai serveur MySQL et les requêtes HTTP par
 * public/index.php. Aucune personne ni non-conformité n'est créée par ce test.
 * TEST_AUTH_MYSQL=1 active volontairement cette recette locale.
 */
if (getenv('TEST_AUTH_MYSQL') !== '1') {
    echo "Authentification MySQL non exécutée : activation explicite TEST_AUTH_MYSQL=1 requise.\n";
    exit(0);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

class AuthMysqlTestFailure extends RuntimeException {}

// Jouer le rôle d'un navigateur : envoyer une requête et conserver les cookies
// pour les appels suivants. $post=null signifie GET ; un tableau signifie POST.
// Le résultat contient le code HTTP, les en-têtes et le HTML de la page.
function auth_mysql_http(int $port, string $path, array &$cookies, ?array $post = null): array
{
    $socket = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 5);
    if (!$socket) { throw new AuthMysqlTestFailure('Serveur HTTP local inaccessible.'); }
    stream_set_timeout($socket, 10);
    $body = $post === null ? '' : http_build_query($post);
    $headers = ['Host' => "127.0.0.1:$port", 'Connection' => 'close'];
    if ($cookies) { $headers['Cookie'] = http_build_query($cookies, '', '; '); }
    if ($post !== null) {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $headers['Content-Length'] = (string) strlen($body);
    }
    $request = ($post === null ? 'GET' : 'POST') . " $path HTTP/1.1\r\n";
    foreach ($headers as $name => $value) { $request .= "$name: $value\r\n"; }
    $request .= "\r\n" . $body;
    try {
        // Une écriture réseau peut n'envoyer qu'une partie du message : continuer
        // jusqu'à avoir transmis toute la requête.
        $offset = 0;
        while ($offset < strlen($request)) {
            $written = fwrite($socket, substr($request, $offset));
            if ($written === false || $written === 0) {
                throw new AuthMysqlTestFailure('Envoi HTTP local impossible.');
            }
            $offset += $written;
        }
        $raw = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);
    } finally {
        fclose($socket);
    }
    if ($metadata['timed_out'] || $raw === false || !str_contains($raw, "\r\n\r\n")) {
        throw new AuthMysqlTestFailure('Réponse HTTP locale incomplète.');
    }
    [$head, $html] = explode("\r\n\r\n", $raw, 2);
    if (!preg_match('#^HTTP/1\.[01] ([0-9]{3})#', $head, $status)) {
        throw new AuthMysqlTestFailure('Statut HTTP local invalide.');
    }
    // Appliquer les changements de cookie demandés par le serveur, notamment
    // son remplacement à la connexion et sa suppression à la déconnexion.
    preg_match_all('/^Set-Cookie: anrt_session=([^;]*)/mi', $head, $matches);
    foreach ($matches[1] as $cookie) {
        if ($cookie === '' || $cookie === 'deleted') { unset($cookies['anrt_session']); }
        else { $cookies['anrt_session'] = $cookie; }
    }
    return [(int) $status[1], $head, $html];
}

// Lire le jeton du formulaire reçu pour envoyer un POST lié à la bonne session.
function auth_mysql_csrf(string $html): string
{
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match)) {
        throw new AuthMysqlTestFailure('Jeton CSRF absent du formulaire.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

// Exécuter le parcours connexion -> formulaire -> déconnexion par HTTP.
// Il s'agit d'une simulation des requêtes d'un navigateur, sans interface graphique.
function auth_mysql_browser(array $config, array $user, callable $check): void
{
    $root = dirname(__DIR__);
    // Isoler sessions, compteur de tentatives et journal pour ne pas perturber
    // une personne utilisant déjà le serveur local sur le port 5000.
    $folder = sys_get_temp_dir() . '/anrt-auth-mysql-' . bin2hex(random_bytes(16));
    if (!mkdir($folder, 0700)) {
        throw new AuthMysqlTestFailure('Dossier temporaire indisponible.');
    }
    $process = null;
    try {
        // Le port 0 demande au système un port libre pour ce serveur de test.
        $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if (!$listener) { throw new AuthMysqlTestFailure('Port HTTP local indisponible.'); }
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        // bootstrap() a chargé .env. Le serveur réutilise cette configuration et
        // les vrais PDO ; seuls le stockage et le cookie des sessions sont isolés.
        $environment = [...getenv(), 'SESSION_PATH' => $folder, 'SESSION_COOKIE_SECURE' => 'false'];
        // Démarrer un serveur PHP séparé avec le routeur réel de l'application.
        $process = proc_open([
            PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
            '-S', "127.0.0.1:$port", '-t', $root . '/public', $root . '/public/index.php',
        ], [['pipe', 'r'], ['file', $folder . '/server.log', 'a'], ['file', $folder . '/server.log', 'a']],
            $pipes, $root, $environment);
        if (!is_resource($process)) { throw new AuthMysqlTestFailure('Démarrage PHP impossible.'); }
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (!proc_get_status($process)['running']) { break; }
            $probe = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 0.1);
            if ($probe) {
                fclose($probe);
                $ready = proc_get_status($process)['running'];
                break;
            }
            usleep(100000);
        }
        if (!$ready) { throw new AuthMysqlTestFailure('Le serveur PHP local ne répond pas.'); }

        // Ouvrir la page et vérifier les protections du cookie de session.
        $cookies = [];
        [$status, $headers, $html] = auth_mysql_http($port, '/connexion', $cookies);
        $check($status === 200 && str_contains($html, 'Matricule'), 'Page de connexion réelle');
        $check(isset($cookies['anrt_session']) && str_contains($headers, 'HttpOnly')
            && str_contains($headers, 'SameSite=Lax') && str_contains($headers, 'Cache-Control: no-store'),
            'Cookie et cache de la connexion');
        // Un POST sans jeton CSRF doit être refusé avant toute connexion.
        [$status] = auth_mysql_http($port, '/connexion', $cookies,
            ['matricule' => '00017', 'password' => 'Anrt-Test-2026!']);
        $check($status === 400, 'Refus HTTP sans CSRF');

        // HTTP 401 signifie que l'authentification est refusée : mauvais mot de
        // passe ou compte expiré, même si ce dernier possède le bon mot de passe.
        foreach ([['00017', 'incorrect'], ['00019', 'Anrt-Test-2026!']] as [$matricule, $password]) {
            [, , $html] = auth_mysql_http($port, '/connexion', $cookies);
            [$status] = auth_mysql_http($port, '/connexion', $cookies,
                ['csrf_token' => auth_mysql_csrf($html), 'matricule' => $matricule, 'password' => $password]);
            $check($status === 401, $matricule === '00019' ? 'Refus HTTP du compte expiré' : 'Refus HTTP du mauvais mot de passe');
        }

        [, , $html] = auth_mysql_http($port, '/connexion', $cookies);
        // Le compte actif doit recevoir une redirection 303 vers le formulaire
        // et un nouvel identifiant de session après acceptation du mot de passe.
        $beforeLogin = $cookies['anrt_session'] ?? null;
        [$status, $headers] = auth_mysql_http($port, '/connexion', $cookies,
            ['csrf_token' => auth_mysql_csrf($html), 'matricule' => '00017', 'password' => 'Anrt-Test-2026!']);
        $check($status === 303 && str_contains($headers, 'Location: /non-conformites/nouvelle'),
            'Connexion HTTP avec le compte MySQL');
        $check(isset($cookies['anrt_session']) && $beforeLogin !== null && $cookies['anrt_session'] !== $beforeLogin,
            'Rotation de la session après connexion');
        [$status, , $html] = auth_mysql_http($port, '/non-conformites/nouvelle', $cookies);
        // Le nom affiché doit provenir du compte lu dans MySQL.
        $identity = htmlspecialchars($user['nom_prenom'] ?: $user['matricule'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $check($status === 200 && str_contains($html, '<span class="identity">' . $identity . '</span>'),
            'Formulaire autorisé et identité issue de MySQL');
        // Garder une copie de l'ancien cookie pour vérifier qu'il devient
        // inutilisable après déconnexion, même si on le renvoie manuellement.
        $active = $cookies['anrt_session'];
        [$status, $headers] = auth_mysql_http($port, '/deconnexion', $cookies,
            ['csrf_token' => auth_mysql_csrf($html)]);
        $check($status === 303 && str_contains($headers, 'Location: /connexion') && !isset($cookies['anrt_session']),
            'Déconnexion et suppression du cookie');
        $replay = ['anrt_session' => $active];
        [$status, $headers] = auth_mysql_http($port, '/non-conformites/nouvelle', $replay);
        $check($status === 302 && str_contains($headers, 'Location: /connexion'),
            'Cookie révoqué non réutilisable');
    } finally {
        // Ce nettoyage s'exécute aussi si une assertion échoue.
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        // Seuls les fichiers de ce dossier aléatoire créé par ce test sont retirés.
        foreach (scandir($folder) as $name) {
            if ($name !== '.' && $name !== '..' && is_file($folder . '/' . $name)) {
                unlink($folder . '/' . $name);
            }
        }
        rmdir($folder);
    }
}

try {
    // Charger la même configuration .env que le site, puis limiter cette recette
    // à la base de test locale afin de ne pas interroger une autre installation.
    $config = bootstrap();
    if ($config['mysql_host'] !== '127.0.0.1' || $config['mysql_database'] !== 'anrt_auth_local') {
        throw new AuthMysqlTestFailure('Test refusé : seule la base anrt_auth_local sur 127.0.0.1 est autorisée.');
    }
    $checks = 0;
    // Une assertion compte un contrôle réussi ou arrête le test avec son libellé.
    $check = static function (bool $condition, string $label) use (&$checks): void {
        if (!$condition) { throw new AuthMysqlTestFailure($label . ' : échec.'); }
        $checks++;
    };
    // Commencer par vérifier les comptes directement dans MySQL : identité,
    // profils, mot de passe incorrect, zéros du matricule et compte expiré.
    $pdo = database($config);
    $user = authenticate($pdo, '00017', 'Anrt-Test-2026!');
    $check($user !== null && $user['matricule'] === '00017' && $user['nom_prenom'] !== '',
        'Compte actif et matricule conservé');
    $check(in_array('RESPONSABLE', $user['profiles'], true), 'Profil du compte responsable');
    $admin = authenticate($pdo, '00018', 'Anrt-Test-2026!');
    $check($admin !== null && in_array('ADMINISTRATEUR', $admin['profiles'], true), 'Profil du compte administrateur');
    $check(authenticate($pdo, '00017', 'incorrect') === null, 'Mauvais mot de passe');
    $check(authenticate($pdo, '17', 'Anrt-Test-2026!') === null, 'Préservation des zéros du matricule');
    $check(authenticate($pdo, '00019', 'Anrt-Test-2026!') === null, 'Compte expiré');
    // Puis vérifier le même mécanisme en passant par les pages HTTP du site.
    auth_mysql_browser($config, $user, $check);
    echo "$checks contrôles d'authentification avec MySQL et le point d'entrée public réussis.\n";
} catch (Throwable $error) {
    // Ne pas afficher les erreurs PDO ni des journaux susceptibles de contenir la configuration.
    fwrite(STDERR, ($error instanceof AuthMysqlTestFailure
        ? $error->getMessage()
        : 'Test d’authentification indisponible (' . get_class($error) . ').') . PHP_EOL);
    exit(1);
}

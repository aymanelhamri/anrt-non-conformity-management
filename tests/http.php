<?php
declare(strict_types=1);

function http_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function http_call(int $port, string $path, array &$cookies, ?string $body = null, array $extraHeaders = []): array
{
    $socket = stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 5);
    if (!$socket) { throw new RuntimeException('Serveur HTTP inaccessible.'); }
    stream_set_timeout($socket, 10);
    $headers = ['Host' => "127.0.0.1:$port", 'Connection' => 'close', ...$extraHeaders];
    if ($cookies) { $headers['Cookie'] = http_build_query($cookies, '', '; '); }
    if ($body !== null) {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $headers['Content-Length'] = (string) strlen($body);
    }
    $request = ($body === null ? 'GET' : 'POST') . " $path HTTP/1.1\r\n";
    foreach ($headers as $key => $value) { $request .= "$key: $value\r\n"; }
    fwrite($socket, $request . "\r\n" . ($body ?? ''));
    $raw = stream_get_contents($socket);
    fclose($socket);
    [$head, $content] = explode("\r\n\r\n", $raw, 2);
    preg_match('#^HTTP/1\.[01] ([0-9]+)#', $head, $status);
    preg_match_all('/^Set-Cookie: anrt_session=([^;]*)/mi', $head, $matches);
    foreach ($matches[1] as $cookie) {
        if ($cookie === '' || $cookie === 'deleted') { unset($cookies['anrt_session']); }
        else { $cookies['anrt_session'] = $cookie; }
    }
    return [(int) $status[1], $head, $content];
}

function html_token(string $html, string $name): string
{
    if (!preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException("Jeton $name absent.");
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function test_http(bool $instrumented): int
{
    $root = dirname(__DIR__);
    $folder = sys_get_temp_dir() . '/anrt-php-http-' . bin2hex(random_bytes(8));
    mkdir($folder, 0700);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $environment = [...getenv(), 'SECRET_KEY' => bin2hex(random_bytes(32)),
        'SESSION_PATH' => $folder, 'SESSION_COOKIE_SECURE' => 'false',
        'PERMISSIONS_FILE' => '', 'DEMO_MODE' => 'true', 'NC_INITIAL_RULES_CONFIRMED' => 'true',
        'TEST_HTTP_STORE' => $folder . '/fake-database.json'];
    $router = $instrumented ? $root . '/tests/http_router.php' : $root . '/public/index.php';
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/public', $router],
        [['pipe', 'r'], ['file', $folder . '/server.log', 'a'], ['file', $folder . '/server.log', 'a']], $pipes, $root, $environment);
    fclose($pipes[0]);
    $tests = 0;
    try {
        for ($i = 0; $i < 50; $i++) {
            $probe = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 0.1);
            if ($probe) { fclose($probe); break; }
            usleep(100000);
        }
        $cookies = [];
        [$status, $headers, $html] = http_call($port, '/connexion', $cookies);
        http_check($status === 200 && str_contains($html, 'Matricule'), 'Page de connexion');
        http_check(str_contains($headers, 'HttpOnly') && str_contains($headers, 'SameSite=Lax') && str_contains($headers, 'Cache-Control: no-store'), 'Cookies et cache');
        $tests += 2;
        foreach (['/assets/app.css', '/assets/app.js'] as $path) {
            [$status, , $body] = http_call($port, $path, $cookies);
            http_check($status === 200 && $body !== '', 'Ressource publique'); $tests++;
        }
        foreach (['/.env', '/config/app.php', '/app/auth.php', '/tests/http_router.php'] as $path) {
            [$status] = http_call($port, $path, $cookies);
            http_check($status === 404, 'Fichier privé exposé'); $tests++;
        }
        foreach (['/non-conformites', '/non-conformites/nouvelle', '/non-conformites/1'] as $path) {
            [$status, $headers] = http_call($port, $path, $cookies);
            http_check($status === 302 && str_contains($headers, 'Location: /connexion'), 'Accès sans authentification'); $tests++;
        }
        [$status] = http_call($port, '/connexion', $cookies, 'matricule=00017&password=secret');
        http_check($status === 400, 'Connexion sans CSRF'); $tests++;
        [$status] = http_call($port, '/deconnexion', $cookies, '');
        http_check($status === 400, 'Déconnexion sans CSRF'); $tests++;
        if (!$instrumented) { return $tests; }

        [, , $html] = http_call($port, '/connexion', $cookies);
        $beforeLogin = $cookies['anrt_session'];
        $payload = http_build_query(['csrf_token' => html_token($html, 'csrf_token'), 'matricule' => '00017', 'password' => 'Test-secret']);
        [$status, $headers] = http_call($port, '/connexion', $cookies, $payload);
        http_check($status === 303 && str_contains($headers, 'Location: /non-conformites') && $cookies['anrt_session'] !== $beforeLogin, 'Connexion et rotation'); $tests++;
        [$status, , $html] = http_call($port, '/non-conformites', $cookies);
        http_check($status === 200 && str_contains($html, 'Aucune non-conformité'), 'Liste vide'); $tests++;
        [$status, , $html] = http_call($port, '/non-conformites/nouvelle', $cookies);
        http_check($status === 200 && str_contains($html, 'Enregistrer une non-conformité'), 'Formulaire'); $tests++;
        $payload = ['csrf_token' => html_token($html, 'csrf_token'), 'submission_token' => html_token($html, 'submission_token'),
            'processus_id' => '1', 'type_nc_id' => '1', 'nature_service_id' => '1',
            'responsable_matricule' => '00017', 'description' => '<script>constat</script>',
            'traitement' => 'Traitement réel', 'date_echeance' => ''];
        [$status, , $html] = http_call($port, '/non-conformites/nouvelle', $cookies, http_build_query([...$payload, 'traitement' => '']));
        http_check($status === 422 && str_contains($html, '&lt;script&gt;') && str_contains($html, $payload['submission_token']), 'Validation et maintien de saisie'); $tests++;
        [$status] = http_call($port, '/non-conformites/nouvelle', $cookies, http_build_query([...$payload, 'statut_code' => 'CLOTUREE']));
        http_check($status === 400, 'Champ protégé'); $tests++;
        [$status] = http_call($port, '/non-conformites/nouvelle', $cookies, http_build_query($payload) . '&description=autre');
        http_check($status === 400, 'Champ répété'); $tests++;
        [$status, $headers] = http_call($port, '/non-conformites/nouvelle', $cookies, http_build_query($payload));
        http_check($status === 303 && str_contains($headers, 'Location: /non-conformites/41'), 'Création'); $tests++;
        [$status, , $html] = http_call($port, '/non-conformites/41', $cookies);
        http_check($status === 200 && str_contains($html, 'Votre non-conformité a été enregistrée.') && str_contains($html, '&lt;script&gt;constat&lt;/script&gt;') && str_contains($html, 'Historique'), 'Confirmation, fiche, échappement et audit'); $tests++;
        [$status] = http_call($port, '/non-conformites/nouvelle', $cookies, http_build_query($payload));
        http_check($status === 303, 'Double soumission'); $tests++;
        [$status] = http_call($port, '/non-conformites/nouvelle', $cookies, http_build_query([...$payload, 'description' => 'autre contenu']));
        http_check($status === 409, 'Réessai avec contenu différent'); $tests++;
        foreach (['PILOTE_PROCESSUS', 'CONSULTATION'] as $profile) {
            [$status] = http_call($port, '/non-conformites/nouvelle', $cookies, null, ['X-Test-Profile' => $profile]);
            http_check($status === 403, 'Création interdite au profil'); $tests++;
            [$status] = http_call($port, '/non-conformites/nouvelle', $cookies, http_build_query($payload), ['X-Test-Profile' => $profile]);
            http_check($status === 403, 'POST interdit au profil'); $tests++;
        }
        foreach (['ADMINISTRATEUR', 'PILOTE_PROCESSUS', 'RESPONSABLE', 'CONSULTATION'] as $profile) {
            [$status] = http_call($port, '/non-conformites', $cookies, null, ['X-Test-Profile' => $profile, 'X-Test-Closed' => 'true']);
            http_check($status === 403, 'Refus par défaut'); $tests++;
        }
        [$status] = http_call($port, '/non-conformites/99', $cookies);
        http_check($status === 404, 'Fiche absente'); $tests++;
        // Vieillir la session native de recette, puis vérifier son refus en HTTP.
        $expire = proc_open([PHP_BINARY, '-r',
            'session_save_path($argv[1]); session_name("anrt_session"); session_id($argv[2]); session_start(); $_SESSION["last_activity"]=time()-1801; session_write_close();',
            $folder, $cookies['anrt_session']], [['pipe', 'r'], ['file', $folder . '/server.log', 'a'], ['file', $folder . '/server.log', 'a']], $expirePipes);
        fclose($expirePipes[0]);
        http_check(proc_close($expire) === 0, 'Préparation expiration');
        [$status] = http_call($port, '/non-conformites', $cookies);
        http_check($status === 302 && !isset($cookies['anrt_session']), 'Expiration effective de la session'); $tests++;
        [, , $html] = http_call($port, '/connexion', $cookies);
        [$status] = http_call($port, '/connexion', $cookies, http_build_query(['csrf_token' => html_token($html, 'csrf_token'), 'matricule' => '00017', 'password' => 'Test-secret']));
        http_check($status === 303, 'Reconnexion après expiration'); $tests++;
        $active = $cookies['anrt_session'];
        [, , $html] = http_call($port, '/non-conformites/41', $cookies);
        [$status] = http_call($port, '/deconnexion', $cookies, http_build_query(['csrf_token' => html_token($html, 'csrf_token')]));
        http_check($status === 303 && !isset($cookies['anrt_session']), 'Déconnexion'); $tests++;
        $replay = ['anrt_session' => $active];
        [$status] = http_call($port, '/non-conformites', $replay);
        http_check($status === 302, 'Cookie révoqué non réutilisable'); $tests++;
        return $tests;
    } finally {
        proc_terminate($process);
        proc_close($process);
        foreach (scandir($folder) as $name) {
            if ($name !== '.' && $name !== '..') { unlink($folder . '/' . $name); }
        }
        rmdir($folder);
    }
}

try {
    $instrumented = test_http(true);
    $public = test_http(false);
    echo "$instrumented contrôles HTTP avec PDO instrumenté ; $public contrôles HTTP via le point d'entrée public réussis.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

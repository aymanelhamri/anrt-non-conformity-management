<?php
declare(strict_types=1);

const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$U3RtaGEvWmd1L2RzSU96RQ$znzEI1pQPE6bBAmtorCidmP7eT9JREosW9m/mmxTT6w';

function verify_password(string $hash, string $password): bool
{
    if (str_starts_with($hash, '$argon2id$')) {
        return password_verify($password, $hash);
    }
    if (preg_match('/^\$2[aby]\$/', $hash) && strlen($password) <= 72 && !str_contains($password, "\0")) {
        return password_verify($password, $hash);
    }
    return false;
}

function load_user(PDO $pdo, string $matricule, bool $lock = false): ?array
{
    $user = query($pdo, 'SELECT a.matricule, p.nom_prenom FROM acces a
        JOIN pers p ON p.matricule=a.matricule
        WHERE a.matricule=? AND a.Date_Deb<=CURRENT_TIMESTAMP
        AND (a.Date_Fin IS NULL OR a.Date_Fin>CURRENT_TIMESTAMP)'
        . ($lock ? ' FOR SHARE' : ''), [$matricule])->fetch();
    if (!$user) {
        return null;
    }
    $user['profiles'] = query($pdo, 'SELECT profil_code FROM acces_profil WHERE matricule=?'
        . ($lock ? ' FOR SHARE' : ''), [$matricule])->fetchAll(PDO::FETCH_COLUMN);
    return $user;
}

function authenticate(PDO $pdo, string $matricule, string $password): ?array
{
    $row = query($pdo, 'SELECT passe FROM acces WHERE matricule=?', [$matricule])->fetch();
    $valid = verify_password($row['passe'] ?? DUMMY_HASH, $password);
    return $valid && $row ? load_user($pdo, $matricule) : null;
}

function start_session(array $config): void
{
    $path = $config['session_path'];
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Stockage des sessions indisponible.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $config['session_seconds']);
    session_save_path($path);
    session_name('anrt_session');
    session_set_cookie_params([
        'lifetime' => $config['session_seconds'], 'path' => '/',
        'secure' => $config['cookie_secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        throw new RuntimeException('Impossible de démarrer la session.');
    }
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] >= $config['session_seconds']) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
    setcookie(session_name(), session_id(), [
        'expires' => time() + $config['session_seconds'], 'path' => '/',
        'secure' => $config['cookie_secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function sign_in(array $user): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['matricule'] = $user['matricule'];
    $_SESSION['last_activity'] = time();
}

function sign_out(array $config): void
{
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', [
        'expires' => 1, 'path' => '/', 'secure' => $config['cookie_secure'],
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function check_csrf(array $post): void
{
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $post['csrf_token'] ?? '')) {
        throw new HttpError(400, 'Le formulaire a expiré. Rechargez la page avant de réessayer.');
    }
}

function allow_login(array $config, string $identity, string $address): bool
{
    // Un fichier privé verrouillé, partagé par les workers de cette machine.
    $file = fopen($config['session_path'] . '/login-attempts.json', 'c+');
    if ($file === false) {
        throw new RuntimeException('Limitation des connexions indisponible.');
    }
    try {
        if (!flock($file, LOCK_EX)) {
            throw new RuntimeException('Verrou des connexions indisponible.');
        }
        $raw = stream_get_contents($file);
        $attempts = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $now = time();
        $attempts = array_filter($attempts, static fn(array $entry): bool => $entry['until'] > $now);
        $keys = [hash('sha256', $address) => 30, hash('sha256', $address . "\0" . $identity) => 8];
        foreach ($keys as $key => $limit) {
            if (($attempts[$key]['count'] ?? 0) >= $limit) {
                return false;
            }
        }
        foreach ($keys as $key => $limit) {
            $entry = $attempts[$key] ?? ['count' => 0, 'until' => $now + 600];
            $entry['count']++;
            $attempts[$key] = $entry;
        }
        $encoded = json_encode($attempts, JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
            throw new RuntimeException('Écriture des limites de connexion impossible.');
        }
        return true;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

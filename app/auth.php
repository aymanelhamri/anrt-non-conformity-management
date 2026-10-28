<?php
declare(strict_types=1);

// Hash de remplacement pour vérifier un mot de passe même si le matricule est
// inconnu : cela réduit les différences de temps de réponse entre les deux cas.
const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$U3RtaGEvWmd1L2RzSU96RQ$znzEI1pQPE6bBAmtorCidmP7eT9JREosW9m/mmxTT6w';

// Comparer le mot de passe saisi au hash lu dans acces.passe.
// password_verify() effectue la vérification sans déchiffrer le hash.
function verify_password(string $hash, string $password): bool
{
    if (str_starts_with($hash, '$argon2id$')) {
        return password_verify($password, $hash);
    }
    // bcrypt est aussi accepté pour les comptes existants. Refuser les mots de
    // passe que cet algorithme risquerait de tronquer au lieu de vérifier en entier.
    if (preg_match('/^\$2[aby]\$/', $hash) && strlen($password) <= 72 && !str_contains($password, "\0")) {
        return password_verify($password, $hash);
    }
    return false;
}

// Relier le compte acces à l'identité pers par le même matricule.
// Un début futur ou une date de fin atteinte empêche le chargement du compte.
// $lock ajoute un verrou de lecture lorsque cet appel appartient à une transaction.
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
    // Les profils sont relus dans MySQL ; app/permissions.php en déduit les droits.
    $user['profiles'] = query($pdo, 'SELECT profil_code FROM acces_profil WHERE matricule=?'
        . ($lock ? ' FOR SHARE' : ''), [$matricule])->fetchAll(PDO::FETCH_COLUMN);
    return $user;
}

// Point d'entrée de la connexion : chercher le matricule dans MySQL, vérifier
// son mot de passe, puis contrôler sa validité. Ce code ne crée aucun compte.
function authenticate(PDO $pdo, string $matricule, string $password): ?array
{
    // Le ? est remplacé par un paramètre PDO, en conservant notamment les zéros.
    $row = query($pdo, 'SELECT passe FROM acces WHERE matricule=?', [$matricule])->fetch();
    $valid = verify_password($row['passe'] ?? DUMMY_HASH, $password);
    return $valid && $row ? load_user($pdo, $matricule) : null;
}

// Tracer les événements de connexion dans le même journal que les créations de NC.
// Cette fonction ne reçoit jamais de mot de passe, de hash ou de cookie de session.
// Un matricule inconnu reste visible dans les refus avec un acteur SQL nul.
function record_auth_event(PDO $pdo, string $identifier, string $operation, ?string $address, ?string $reason = null): void
{
    if (!in_array($operation, ['CONNEXION', 'CONNEXION_REFUSEE', 'DECONNEXION'], true)) {
        throw new InvalidArgumentException('Événement de connexion inconnu.');
    }
    $subject = mb_substr($identifier, 0, 60, 'UTF-8');
    $actor = null;
    if ($identifier !== '' && mb_strlen($identifier, 'UTF-8') <= 60) {
        $person = query($pdo, 'SELECT matricule FROM pers WHERE matricule=?', [$identifier])->fetch();
        $actor = $person ? $person['matricule'] : null;
    }
    $details = ['matricule' => $subject];
    if ($reason !== null) {
        $details['motif'] = $reason;
    }
    query($pdo, "INSERT INTO journal_audit
        (acteur_matricule, operation, entite, entite_id, nouvelles_valeurs, adresse_ip)
        VALUES (?,?,'acces',?,?,?)", [$actor, $operation, $subject !== '' ? $subject : 'inconnu',
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $address !== null && filter_var($address, FILTER_VALIDATE_IP) ? $address : null]);
}

// Une session conserve l'état de connexion entre les pages. Ses données restent
// dans un dossier privé ; le navigateur reçoit seulement un identifiant en cookie.
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
    // Après la durée d'inactivité configurée (30 minutes par défaut), supprimer
    // l'état de connexion et changer l'identifiant de session.
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

// Après une connexion acceptée, changer l'identifiant de session. Le matricule
// est mémorisé, mais le mot de passe n'est jamais placé dans la session.
function sign_in(array $user): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['matricule'] = $user['matricule'];
    $_SESSION['last_activity'] = time();
}

// Déconnecter la personne en supprimant sa session et le cookie du navigateur.
function sign_out(array $config): void
{
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', [
        'expires' => 1, 'path' => '/', 'secure' => $config['cookie_secure'],
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

// Ce jeton aléatoire relie un formulaire à la session qui l'a ouvert.
// Il accompagne les POST de connexion, de déconnexion et de déclaration.
function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

// Refuser un formulaire dont le jeton est absent, expiré ou différent de celui
// de la session. hash_equals() compare les valeurs sans raccourci selon leur contenu.
function check_csrf(array $post): void
{
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $post['csrf_token'] ?? '')) {
        throw new HttpError(400, 'Le formulaire a expiré. Rechargez la page avant de réessayer.');
    }
}

function allow_login(array $config, string $identity, string $address): bool
{
    // Compter les tentatives dans un fichier privé partagé par les processus PHP
    // de cette machine. Le verrou empêche deux mises à jour simultanées de s'écraser.
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
        // Retirer les fenêtres expirées. Les clés masquent l'IP et le matricule
        // dans le fichier ; elles ne contiennent aucun mot de passe.
        $attempts = array_filter($attempts, static fn(array $entry): bool => $entry['until'] > $now);
        $keys = [hash('sha256', $address) => 30, hash('sha256', $address . "\0" . $identity) => 8];
        // Limites sur dix minutes : 30 essais par IP et 8 par couple IP/matricule.
        // Toute tentative autorisée compte, même si le mot de passe est correct.
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

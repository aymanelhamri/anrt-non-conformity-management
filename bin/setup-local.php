<?php
declare(strict_types=1);

/*
 * Ce script prépare une seule fois la base MySQL anrt_auth_local et ses comptes
 * fictifs. Il s'exécute dans le terminal, jamais depuis une page du site.
 * Les comptes sont enregistrés dans MySQL par les INSERT plus bas ; ce fichier
 * PHP contient les instructions de création, pas le stockage des comptes.
 * Il refuse de recommencer si la base, le compte MySQL ou .env existe déjà.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Découpe un fichier SQL en commandes que PDO pourra envoyer à MySQL.
 * DELIMITER permet de garder une procédure entière dans une seule commande,
 * même si elle contient plusieurs points-virgules.
 *
 * @return list<string>
 */
function local_sql_statements(string $path): array
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Lecture du script SQL impossible : ' . basename($path));
    }
    // Retirer l'éventuelle marque UTF-8 placée au tout début du fichier.
    $source = preg_replace('/^\xEF\xBB\xBF/', '', $source);
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (explode("\n", $source) as $line) {
        $line = rtrim($line);
        if (trim($line) === '' || str_starts_with(ltrim($line), '--')) {
            continue;
        }
        if (preg_match('/^DELIMITER (\S+)$/D', $line, $match)) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Délimiteur SQL inattendu : ' . basename($path));
            }
            $delimiter = $match[1];
            continue;
        }
        $buffer .= $line . "\n";
        if (!str_ends_with(rtrim($buffer), $delimiter)) {
            continue;
        }
        $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
        $buffer = '';
        // Les scripts d'origine visent anrt_qualite. Ignorer leurs commandes de
        // choix de base permet de réutiliser les tables dans anrt_auth_local.
        if (preg_match('/^CREATE DATABASE IF NOT EXISTS anrt_qualite\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci$/D', $statement)
            || $statement === 'USE anrt_qualite') {
            continue;
        }
        if (preg_match('/^(?:USE\b|(?:CREATE|ALTER|DROP)\s+DATABASE\b)/i', $statement)) {
            throw new RuntimeException('Changement de base interdit : ' . basename($path));
        }
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
    if (trim($buffer) !== '' || $delimiter !== ';' || $statements === []) {
        throw new RuntimeException('Script SQL incomplet : ' . basename($path));
    }
    return $statements;
}

// Les chemins partent de la racine du projet, quel que soit le dossier du terminal.
$root = dirname(__DIR__);
$databaseName = 'anrt_auth_local';
// Ce compte technique reliera PHP à MySQL. Il est distinct des matricules du site.
$applicationUser = 'anrt_local';
// Mémoriser l'étape en cours permet d'expliquer un échec sans afficher de secret.
$stage = 'vérification des prérequis';
$databaseCreated = false;
$pdo = null;

try {
    if (PHP_VERSION_ID < 80300 || !extension_loaded('pdo_mysql')
        || !extension_loaded('mbstring') || !defined('PASSWORD_ARGON2ID')) {
        throw new RuntimeException('PHP 8.3+, PDO MySQL, mbstring et Argon2id sont nécessaires.');
    }
    // .env contient la connexion utilisée par l'application : ne pas la remplacer.
    $environmentPath = $root . '/.env';
    if (file_exists($environmentPath) || is_link($environmentPath)) {
        throw new RuntimeException('.env existe déjà : aucune configuration ni base ne sera remplacée.');
    }
    // Le compte administrateur MySQL sert uniquement à installer la base et les droits.
    // Ses paramètres restent dans instance/, un dossier privé ignoré par Git.
    $setupPath = $root . '/instance/mysql-local/setup.json';
    if (!is_file($setupPath)) {
        throw new RuntimeException('Configuration privée absente : instance/mysql-local/setup.json.');
    }
    $setup = json_decode(file_get_contents($setupPath), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($setup) || ($setup['host'] ?? null) !== '127.0.0.1'
        || !in_array($setup['port'] ?? null, [3307, '3307'], true)
        || ($setup['user'] ?? null) !== 'root'
        || !is_string($setup['password'] ?? null) || $setup['password'] === '') {
        throw new RuntimeException('setup.json doit désigner root sur la boucle locale 127.0.0.1:3307 avec son mot de passe.');
    }
    // Lire tous les scripts avant de créer la base : tables, listes, procédure et vues.
    $scripts = [];
    foreach (['001_schema.sql', '002_seed_referentiels.sql', '003_procedures.sql', '004_views.sql'] as $name) {
        $scripts[$name] = local_sql_statements($root . '/database/' . $name);
    }
    // Trois valeurs différentes : mot de passe technique MySQL, clé de sécurité
    // de l'application et hash du mot de passe saisi par les utilisateurs de test.
    $applicationPassword = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    // Le texte Anrt-Test-2026! sert aux essais. Seul son hash sera inséré dans acces.passe.
    // password_verify() dans app/auth.php vérifiera ensuite le mot de passe saisi.
    $passwordHash = password_hash('Anrt-Test-2026!', PASSWORD_ARGON2ID);
    // Le nom de cette option PDO dépend de la version de PHP installée.
    $multiStatementsOption = defined('Pdo\\Mysql::ATTR_MULTI_STATEMENTS')
        ? constant('Pdo\\Mysql::ATTR_MULTI_STATEMENTS')
        : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
    // PDO ouvre la connexion PHP/MySQL. Les commandes préparées utilisent des
    // paramètres séparés du SQL ; l'envoi de plusieurs commandes à la fois est désactivé.
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        $multiStatementsOption => false,
    ];
    $dsn = 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4';
    $stage = 'connexion au serveur MySQL local';
    $pdo = new PDO($dsn, $setup['user'], $setup['password'], $options);
    $pdo->exec("SET time_zone = '+00:00'");
    // Vérifier les noms avant toute création pour préserver une installation existante.
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $check->execute([$databaseName]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('La base anrt_auth_local existe déjà : arrêt sans modification.');
    }
    $check = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
    $check->execute([$applicationUser]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('Le compte MySQL anrt_local existe déjà : arrêt sans modification.');
    }

    $stage = 'création de la nouvelle base';
    // Sans IF NOT EXISTS : une collision, même concurrente, doit provoquer un échec.
    $pdo->exec('CREATE DATABASE `anrt_auth_local` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $databaseCreated = true;
    $pdo->exec('USE `anrt_auth_local`');
    // Tout le schéma est importé pour que le formulaire s'affiche après connexion.
    // Aucune non-conformité ni action métier n'est ajoutée ici.
    foreach ($scripts as $name => $statements) {
        $stage = 'import de ' . $name;
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    $stage = 'création des trois comptes synthétiques';
    // Une transaction regroupe les trois comptes : leurs fiches, accès et profils
    // sont validés ensemble par commit(), ou annulés ensemble en cas d'erreur.
    $pdo->beginTransaction();
    // pers : identité de la personne, avec le matricule conservé comme chaîne.
    $person = $pdo->prepare('INSERT INTO pers (matricule, nom_prenom) VALUES (?, ?)');
    // acces : matricule, hash du mot de passe et période d'autorisation de connexion.
    // expired=1 donne une fin hier ; expired=0 donne NULL, donc aucune date de fin.
    $access = $pdo->prepare('INSERT INTO acces (matricule, nom, passe, Date_Deb, Date_Fin)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP - INTERVAL 2 DAY,
            CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP - INTERVAL 1 DAY ELSE NULL END)');
    // acces_profil : rôle applicatif de la personne, utilisé pour calculer ses droits.
    $profile = $pdo->prepare('INSERT INTO acces_profil (matricule, profil_code) VALUES (?, ?)');
    // Chaque ligne contient : matricule, nom fictif, profil, indicateur d'expiration.
    // Les guillemets autour de 00017, etc. préservent les zéros du matricule.
    foreach ([
        ['00017', 'Responsable Test', 'RESPONSABLE', 0],
        ['00018', 'Administrateur Test', 'ADMINISTRATEUR', 0],
        ['00019', 'Compte Expiré', 'RESPONSABLE', 1],
    ] as [$matricule, $name, $profileCode, $expired]) {
        // Les ? des requêtes reçoivent les valeurs dans cet ordre. Créer l'identité
        // avant l'accès, puis le profil, respecte les liens entre les trois tables.
        $person->execute([$matricule, $name]);
        $access->execute([$matricule, $name, $passwordHash, $expired]);
        $profile->execute([$matricule, $profileCode]);
    }
    $pdo->commit();

    $stage = 'création du compte MySQL applicatif';
    // PHP utilisera ce compte limité au lieu de root pour les connexions courantes.
    // Les personnes se connecteront au site avec leur matricule, pas avec anrt_local.
    $account = "'anrt_local'@'127.0.0.1'";
    $pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($applicationPassword));
    $pdo->exec('GRANT SELECT ON `anrt_auth_local`.* TO ' . $account);
    $pdo->exec('GRANT INSERT ON `anrt_auth_local`.`non_conformites` TO ' . $account);
    $pdo->exec('GRANT INSERT ON `anrt_auth_local`.`journal_audit` TO ' . $account);
    $pdo->exec('GRANT UPDATE (type_nc_id, nature_service_id, description, traitement, responsable_matricule, date_echeance) ON `anrt_auth_local`.`non_conformites` TO ' . $account);
    $pdo->exec('GRANT INSERT, UPDATE (lue_le) ON `anrt_auth_local`.`notifications` TO ' . $account);
    $pdo->exec('GRANT EXECUTE ON PROCEDURE `anrt_auth_local`.`generer_reference` TO ' . $account);

    $stage = 'vérification de la connexion applicative';
    // Essayer les identifiants techniques définitifs avant de les écrire dans .env.
    $application = new PDO($dsn . ';dbname=anrt_auth_local', $applicationUser, $applicationPassword, $options);
    if ((int) $application->query('SELECT COUNT(*) FROM acces')->fetchColumn() !== 3) {
        throw new RuntimeException('Le contrôle des comptes de test a échoué.');
    }

    $stage = 'écriture privée de .env';
    // .env contient les paramètres du serveur MySQL, pas les mots de passe des
    // personnes. DEMO_MODE active les droits d'essai ; les comptes restent dans MySQL.
    // Le cookie non Secure convient au serveur HTTP local sur 127.0.0.1.
    $environment = implode("\n", [
        '# Configuration locale de test ; données synthétiques uniquement.',
        'SECRET_KEY=' . $secret,
        'MYSQL_HOST=127.0.0.1',
        'MYSQL_PORT=3307',
        'MYSQL_DATABASE=anrt_auth_local',
        'MYSQL_USER=anrt_local',
        'MYSQL_PASSWORD=' . $applicationPassword,
        'MYSQL_SSL_CA=',
        'APP_TIMEZONE=Africa/Casablanca',
        'SESSION_COOKIE_SECURE=false',
        'SESSION_PATH=',
        'DEMO_MODE=true',
        'LOGIN_IDENTIFIER=matricule',
        'NC_INITIAL_RULES_CONFIRMED=false',
        'NC_DEADLINE_REQUIRED=false',
        'PERMISSIONS_FILE=',
        '',
    ]);
    // Le mode x interdit aussi l'écrasement si .env apparaît pendant l'import.
    $handle = @fopen($environmentPath, 'x');
    if ($handle === false) {
        throw new RuntimeException('Création de .env refusée ; vérifier sa présence et les droits du dossier.');
    }
    try {
        // Restreindre la lecture du fichier lorsque le système gère ces permissions.
        chmod($environmentPath, 0600);
        if (fwrite($handle, $environment) !== strlen($environment) || !fflush($handle)) {
            throw new RuntimeException('Écriture de .env incomplète ; vérifier ce fichier avant utilisation.');
        }
    } finally {
        fclose($handle);
    }
    echo "Base anrt_auth_local créée : 2 comptes actifs et 1 compte expiré.\n";
    echo "Configuration enregistrée dans .env ; identifiants de test : docs/authentification-locale.md.\n";
} catch (Throwable $error) {
    // Le rollback concerne la transaction des comptes encore ouverte. Les tables
    // déjà créées par les scripts SQL sont conservées pour comprendre l'échec.
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Les erreurs MySQL peuvent contenir du SQL : ne jamais exposer les secrets.
    $detail = $error instanceof PDOException
        ? 'MySQL a refusé cette étape (code ' . ($error->errorInfo[1] ?? $error->getCode()) . ').'
        : ($error instanceof JsonException ? 'setup.json contient un JSON invalide.' : $error->getMessage());
    fwrite(STDERR, 'Échec pendant ' . $stage . ' : ' . $detail . PHP_EOL);
    if ($databaseCreated) {
        fwrite(STDERR, "La base nouvellement créée est conservée pour diagnostic ; aucune suppression automatique.\n");
    }
    exit(1);
}

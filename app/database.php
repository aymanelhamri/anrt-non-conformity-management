<?php
declare(strict_types=1);

/*
 * Ce fichier relie le code PHP au serveur MySQL avec PDO.
 * database() ouvre la connexion ; query() envoie une commande SQL avec ses valeurs.
 * Les comptes du site restent dans les tables MySQL, notamment pers et acces.
 */

// $config contient les paramètres chargés par config/app.php depuis .env.
// MYSQL_USER et MYSQL_PASSWORD identifient le compte technique MySQL ; ce ne sont
// pas le matricule et le mot de passe saisis sur la page de connexion.
function database(array $config): PDO
{
    $options = [
        // Transformer les erreurs MySQL en exceptions, gérées par le code appelant.
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Lire les lignes sous forme de tableaux nommés : $row['matricule'], etc.
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Faire préparer les requêtes par MySQL plutôt que de les simuler dans PHP.
        PDO::ATTR_EMULATE_PREPARES => false,
        // Limiter l'attente de connexion à cinq secondes si le serveur ne répond pas.
        PDO::ATTR_TIMEOUT => 5,
        // Ne pas conserver automatiquement une connexion pour d'autres requêtes HTTP.
        PDO::ATTR_PERSISTENT => false,
    ];
    // Pour un serveur configuré avec TLS, utiliser le certificat d'autorité fourni
    // et vérifier le certificat du serveur avant d'échanger les données.
    if ($config['mysql_ssl_ca']) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $config['mysql_ssl_ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    // Le DSN décrit la destination : adresse, port et nom de base.
    // utf8mb4 permet de transmettre les accents et les caractères sur quatre octets.
    $dsn = "mysql:host={$config['mysql_host']};port={$config['mysql_port']};"
        . "dbname={$config['mysql_database']};charset=utf8mb4";
    // L'ouverture peut lever une PDOException si les accès ou le serveur sont invalides.
    $pdo = new PDO($dsn, $config['mysql_user'], $config['mysql_password'], $options);
    // Utiliser UTC pour les horodatages SQL et un mode strict pour refuser, entre
    // autres, les valeurs tronquées ou certaines dates invalides.
    $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    // Le même objet PDO pourra servir à plusieurs requêtes SQL pendant le traitement.
    return $pdo;
}

// Exemple : query($pdo, 'SELECT passe FROM acces WHERE matricule=?', ['00020']).
// Le SQL contient un ? ; la valeur correspondante est transmise séparément à PDO.
function query(PDO $pdo, string $sql, array $parameters = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    foreach (array_values($parameters) as $index => $value) {
        // Choisir le type de chaque valeur. Un matricule comme '00020' reste une
        // chaîne : il ne faut pas le convertir en nombre et perdre ses zéros.
        $type = is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // Les positions PDO commencent à 1, alors que les indices du tableau commencent à 0.
        $statement->bindValue($index + 1, $value, $type);
    }
    // Exécuter la commande, puis laisser l'appelant lire son résultat avec
    // fetch() pour une ligne, fetchAll() pour plusieurs ou fetchColumn() pour une valeur.
    $statement->execute();
    return $statement;
}

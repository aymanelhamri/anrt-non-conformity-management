<?php
declare(strict_types=1);

/*
 * Enregistrement des informations d'une non-conformité (NC).
 * Le formulaire templates/new.php envoie les champs à app/web.php, qui appelle
 * create_nc() ci-dessous après avoir identifié l'utilisateur et contrôlé le CSRF.
 * Ce fichier vérifie les données, génère une référence puis écrit dans les tables
 * MySQL non_conformites et journal_audit. Les informations restent dans la base,
 * pas dans ce fichier PHP.
 */

// Champs que l'utilisateur peut renseigner. Le déclarant, la référence, la date
// de création et le statut initial seront déterminés par le serveur.
const NC_FIELDS = ['processus_id', 'type_nc_id', 'nature_service_id', 'description', 'traitement', 'responsable_matricule', 'date_echeance'];

// Regrouper les erreurs par champ pour que app/web.php puisse les réafficher
// près des données à corriger. Le statut 422 indique une saisie non valide.
class FormError extends RuntimeException
{
    public function __construct(public array $errors, public int $status = 422)
    {
        parent::__construct('Données invalides.');
    }
}

// Lire un identifiant positif compatible avec une colonne MySQL INT UNSIGNED.
// Cette vérification porte sur le format ; l'existence en base sera contrôlée après.
function uint(string $value): ?int
{
    if (strlen($value) > 10 || !preg_match('/^[0-9]+$/D', $value)) {
        return null;
    }
    $number = (int) $value;
    return $number >= 1 && $number <= 4294967295 ? $number : null;
}

// Utiliser la date du jour dans le fuseau métier configuré, au format SQL AAAA-MM-JJ.
function business_today(array $config): string
{
    return (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');
}

// Contrôler les valeurs reçues avant de les enregistrer. Cette fonction ne fait
// aucune requête MySQL : elle vérifie les formats, tailles, textes requis et dates.
function validate_nc(array $values, string $today, bool $deadlineRequired = false): array
{
    $errors = $data = [];
    foreach (['processus_id', 'type_nc_id', 'nature_service_id'] as $field) {
        $data[$field] = uint($values[$field] ?? '');
        if ($data[$field] === null) {
            $errors[$field] = 'Sélectionnez une valeur valide.';
        }
    }
    // Une description ou un traitement composé seulement d'espaces est refusé.
    // La limite d'un TEXT MySQL est de 65 535 octets, pas de 65 535 caractères.
    foreach (['description', 'traitement'] as $field) {
        $value = $values[$field] ?? '';
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors[$field] = 'Ce texte doit être encodé en UTF-8.';
        } elseif (preg_match('/^[\s\p{Z}]*$/uD', $value)) {
            $errors[$field] = 'Ce champ est obligatoire.';
        } elseif (strlen($value) > 65535) {
            $errors[$field] = 'Ce texte dépasse la capacité autorisée (65 535 octets UTF-8).';
        }
        $data[$field] = $value;
    }
    // Le responsable est identifié par son matricule, conservé comme texte.
    // Sa présence et son autorisation dans ce dossier seront vérifiées en base.
    $matricule = $values['responsable_matricule'] ?? '';
    if (!mb_check_encoding($matricule, 'UTF-8') || mb_strlen($matricule, 'UTF-8') > 60
        || preg_match('/^[\s\p{Z}]*$/uD', $matricule)) {
        $errors['responsable_matricule'] = 'Sélectionnez un responsable (matricule de 60 caractères maximum).';
    }
    $data['responsable_matricule'] = $matricule; // Conserver zéros, espaces et contenu, sans troncature.
    // Une échéance vide devient NULL, sauf si la configuration la rend obligatoire.
    // Une échéance renseignée doit être une vraie date, non antérieure à la création.
    $deadline = $values['date_echeance'] ?? '';
    $data['date_echeance'] = null;
    if ($deadline !== '') {
        $validFormat = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $deadline);
        // Comparer ensuite la date reconstruite au texte reçu permet notamment
        // de refuser le 30 février, que PHP peut normaliser en une date de mars.
        $date = $validFormat ? DateTimeImmutable::createFromFormat('!Y-m-d', $deadline, new DateTimeZone('UTC')) : false;
        if (!$validFormat || !$date
            || $date->format('Y-m-d') !== $deadline || substr($deadline, 0, 4) < '1000' || $deadline < $today) {
            $errors['date_echeance'] = 'Saisissez une date valide égale ou postérieure à la création.';
        } else {
            $data['date_echeance'] = $deadline;
        }
    } elseif ($deadlineRequired) {
        $errors['date_echeance'] = "L'échéance est obligatoire.";
    }
    // Renvoyer toutes les erreurs ensemble, ou les valeurs validées si tout convient.
    if ($errors) {
        throw new FormError($errors);
    }
    return $data;
}

// Donner à chaque nouveau formulaire une clé aléatoire signée pour son utilisateur.
// Elle repère un même envoi lors d'un réessai ; le jeton CSRF est contrôlé séparément.
function new_submission(array $user, array $config): string
{
    $nonce = bin2hex(random_bytes(32));
    return $nonce . ':' . hash_hmac('sha256', 'nc|' . $user['matricule'] . '|' . $nonce, $config['secret']);
}

// Vérifier que la signature correspond bien au matricule connecté, puis produire
// l'empreinte de la clé qui sera conservée dans l'audit pour retrouver cet envoi.
function submission_key(string $token, string $actor, array $config): string
{
    if (!preg_match('/^([a-f0-9]{64}):([a-f0-9]{64})$/D', $token, $parts)
        || !hash_equals(hash_hmac('sha256', 'nc|' . $actor . '|' . $parts[1], $config['secret']), $parts[2])) {
        throw new FormError(['form' => "La clé d'enregistrement est invalide. Ouvrez un nouveau formulaire."], 400);
    }
    return hash('sha256', $token);
}

/**
 * Enregistrer une NC, ou retrouver celle déjà créée par le même formulaire.
 * Une transaction regroupe le compteur de référence, la NC et sa trace d'audit.
 *
 * @return array{0:int, 1:bool, 2:string} Identifiant, réessai retrouvé, référence.
 */
function create_nc(PDO $pdo, array $config, array $user, array $values, string $token, ?string $address): array
{
    // Vérifier les droits même si la fonction est appelée autrement que par la page Web.
    if (!can_create($user, $config)) {
        throw new FormError(['form' => "Vous n'avez pas le droit de créer une non-conformité."], 403);
    }
    if (!$config['demo'] && !$config['initial_rules_confirmed']) {
        throw new FormError(['form' => 'La création attend la confirmation des règles de saisie initiale.'], 403);
    }
    // L'empreinte du contenu permet de distinguer un renvoi identique d'un
    // formulaire dont les champs auraient été modifiés après un premier succès.
    $key = submission_key($token, $user['matricule'], $config);
    $content = array_intersect_key($values, array_flip(NC_FIELDS));
    ksort($content);
    $fingerprint = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    // Sur ce serveur MySQL, un verrou nommé fait traiter deux envois de la même
    // clé l'un après l'autre. Il reste indépendant de la transaction SQL.
    $lock = 'nc:' . substr(hash('sha256', $config['mysql_database'] . $key), 0, 60);
    $locked = false;
    try {
        // Attendre au plus dix secondes si ce même formulaire est déjà en cours.
        $locked = (int) query($pdo, 'SELECT GET_LOCK(?, 10)', [$lock])->fetchColumn() === 1;
        if (!$locked) {
            throw new FormError(['form' => 'Cet enregistrement est en cours. Réessayez avec le même formulaire.'], 409);
        }
        // Les écritures ne seront validées qu'au commit() final. En cas d'erreur
        // avant validation, rollback() pourra annuler cette transaction.
        $pdo->beginTransaction();
        // Relire et verrouiller le compte pour tenir compte d'une expiration
        // ou d'un changement de profils depuis l'ouverture du formulaire.
        $currentUser = load_user($pdo, $user['matricule'], true);
        if (!$currentUser || !can_create($currentUser, $config)) {
            throw new FormError(['form' => 'Votre compte ou vos permissions ne permettent plus cet enregistrement.'], 403);
        }
        // Chercher d'abord un succès précédent de cette clé dans l'audit. Cela
        // permet de récupérer le résultat si la première réponse n'est pas arrivée.
        $previous = query($pdo, "SELECT entite_id, nouvelles_valeurs FROM journal_audit
            WHERE acteur_matricule=? AND entite='non_conformites' AND operation='CREATION'
            AND JSON_UNQUOTE(JSON_EXTRACT(nouvelles_valeurs, '$._submission.key'))=?
            ORDER BY id LIMIT 1", [$user['matricule'], $key])->fetch();
        if ($previous) {
            $snapshot = json_decode($previous['nouvelles_valeurs'], true, 512, JSON_THROW_ON_ERROR);
            // Une clé déjà utilisée ne peut pas servir à enregistrer un autre contenu.
            if ($snapshot['_submission']['fingerprint'] !== $fingerprint) {
                throw new FormError(['form' => 'Ce formulaire a déjà enregistré un autre contenu. Ouvrez une nouvelle déclaration.'], 409);
            }
            // Aucun nouvel INSERT n'est nécessaire : fermer la transaction de
            // lecture et retourner l'identifiant et la référence déjà enregistrés.
            $pdo->rollBack();
            return [(int) $previous['entite_id'], true, $snapshot['reference']];
        }
        // Un succès d'hier reste récupérable avant de revalider l'échéance.
        $today = business_today($config);
        // Pour une nouvelle création, contrôler d'abord les champs du formulaire.
        $data = validate_nc($values, $today, $config['deadline_required']);
        $errors = [];
        // Un numéro au bon format ne suffit pas : la valeur choisie doit exister
        // et être active. FOR SHARE la verrouille en lecture pendant la transaction.
        foreach (['processus_id' => 'processus', 'type_nc_id' => 'types_nc', 'nature_service_id' => 'natures_service'] as $field => $table) {
            if (!query($pdo, "SELECT id FROM $table WHERE id=? AND actif=TRUE FOR SHARE", [$data[$field]])->fetch()) {
                $errors[$field] = "Cette valeur n'existe plus ou est inactive.";
            }
        }
        // Le responsable doit appartenir au personnel et au périmètre d'affectation
        // permis à l'utilisateur connecté pour le processus sélectionné.
        [$where, $parameters] = assignment_sql($currentUser, $config, $data['processus_id']);
        if (!query($pdo, "SELECT p.matricule FROM pers p WHERE p.matricule=? AND $where FOR SHARE",
            [$data['responsable_matricule'], ...$parameters])->fetch()) {
            $errors['responsable_matricule'] = "Ce responsable n'existe pas ou n'est pas autorisé pour ce dossier.";
        }
        if ($errors) {
            throw new FormError($errors);
        }
        // Appeler la procédure SQL qui incrémente le compteur du processus et de
        // l'année. Elle place une référence, par exemple NC-SI-001-26, dans
        // @anrt_reference, une variable de cette même connexion MySQL.
        $statement = query($pdo, 'CALL generer_reference(?, ?, ?, @anrt_reference)', ['NC', $data['processus_id'], $today]);
        while ($statement->nextRowset()) {
            // Vider les résultats de CALL avant la prochaine requête PDO.
        }
        $statement->closeCursor();
        $number = query($pdo, "SELECT derniere_valeur FROM compteurs_reference
            WHERE type_document='NC' AND processus_id=? AND annee=?", [$data['processus_id'], (int) substr($today, 0, 4)])->fetchColumn();
        // Le format actuel ne prévoit que trois chiffres : refuser le dépassement.
        // Le compteur modifié dans la transaction sera alors annulé avec elle.
        if ((int) $number > 999) {
            throw new FormError(['form' => "La limite annuelle de 999 références pour ce processus est atteinte. Contactez l'administrateur."], 409);
        }
        $reference = query($pdo, 'SELECT @anrt_reference')->fetchColumn();
        if (!is_string($reference) || $reference === '' || mb_strlen($reference, 'UTF-8') > 50) {
            throw new FormError(['form' => "La référence générée est invalide. Contactez l'administrateur."], 409);
        }
        // Enregistrer ici les informations du formulaire dans non_conformites.
        // Les ? reçoivent les valeurs du tableau suivant dans le même ordre.
        // Le déclarant vient de la session ; la date et le statut OUVERTE sont
        // fixés côté serveur, et la référence vient de la procédure MySQL.
        query($pdo, "INSERT INTO non_conformites
            (reference, processus_id, type_nc_id, nature_service_id, enregistree_par_matricule,
             description, traitement, responsable_matricule, date_creation, date_echeance, statut_code)
            VALUES (?,?,?,?,?,?,?,?,?,?,'OUVERTE')",
            [$reference, $data['processus_id'], $data['type_nc_id'], $data['nature_service_id'], $user['matricule'],
             $data['description'], $data['traitement'], $data['responsable_matricule'], $today, $data['date_echeance']]);
        // Récupérer l'identifiant automatique de la nouvelle ligne, puis relire
        // son contenu exact pour construire la trace de création.
        $id = (int) $pdo->lastInsertId();
        $snapshot = query($pdo, 'SELECT * FROM non_conformites WHERE id=?', [$id])->fetch();
        // Ajouter les empreintes de l'envoi à la copie destinée à l'audit.
        // Elles permettent de retrouver une création sans en produire une seconde.
        $snapshot['_submission'] = ['key' => $key, 'fingerprint' => $fingerprint];
        // journal_audit mémorise qui a créé la NC, son identifiant, les données
        // enregistrées et l'adresse IP reçue par le serveur.
        query($pdo, "INSERT INTO journal_audit
            (acteur_matricule, operation, entite, entite_id, nouvelles_valeurs, adresse_ip)
            VALUES (?,'CREATION','non_conformites',?,?,?)",
            [$user['matricule'], (string) $id, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $address]);
        // Les notifications font partie de cette même transaction. Un réessai
        // retrouvé plus haut n'en crée pas une deuxième série.
        notify_nc($pdo, $config, $currentUser, $snapshot, 'CREATION');
        // Valider ensemble la référence, la NC et l'audit. false indique à la
        // page Web qu'il s'agit d'une nouvelle création, pas d'un réessai retrouvé.
        $pdo->commit();
        return [$id, false, $reference];
    } catch (Throwable $error) {
        try {
            // Si la transaction est encore ouverte, annuler ses écritures pour
            // éviter de conserver une NC dépourvue d'audit ou un compteur seul.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (PDOException) {
            // Après une perte de connexion, le résultat du commit peut être inconnu.
        }
        throw $error;
    } finally {
        // Libérer le verrou nommé après succès comme après erreur : commit() et
        // rollback() ne le libèrent pas automatiquement.
        if ($locked) {
            try {
                query($pdo, 'SELECT RELEASE_LOCK(?)', [$lock])->closeCursor();
            } catch (PDOException) {
                // La fermeture de cette connexion non persistante libère aussi le verrou.
            }
        }
    }
}

// Fournir les listes du formulaire depuis MySQL : référentiels actifs et
// responsables autorisés. La création les vérifie à nouveau lors de l'envoi.
function nc_choices(PDO $pdo, array $config, array $user): array
{
    $tables = ['processus' => 'id, code, nom', 'types_nc' => 'id, libelle', 'natures_service' => 'id, libelle'];
    $result = [];
    foreach ($tables as $table => $columns) {
        $result[$table] = query($pdo, "SELECT $columns FROM $table WHERE actif=TRUE ORDER BY id")->fetchAll();
    }
    [$where, $parameters] = assignment_sql($user, $config);
    $result['responsables'] = query($pdo, "SELECT p.matricule, p.nom_prenom FROM pers p WHERE $where ORDER BY p.nom_prenom, p.matricule", $parameters)->fetchAll();
    return $result;
}

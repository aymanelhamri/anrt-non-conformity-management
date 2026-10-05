<?php
declare(strict_types=1);

const NC_FIELDS = ['processus_id', 'type_nc_id', 'nature_service_id', 'description', 'traitement', 'responsable_matricule', 'date_echeance'];

class FormError extends RuntimeException
{
    public function __construct(public array $errors, public int $status = 422)
    {
        parent::__construct('Données invalides.');
    }
}

function uint(string $value): ?int
{
    if (strlen($value) > 10 || !preg_match('/^[0-9]+$/D', $value)) {
        return null;
    }
    $number = (int) $value;
    return $number >= 1 && $number <= 4294967295 ? $number : null;
}

function business_today(array $config): string
{
    return (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');
}

function validate_nc(array $values, string $today, bool $deadlineRequired = false): array
{
    $errors = $data = [];
    foreach (['processus_id', 'type_nc_id', 'nature_service_id'] as $field) {
        $data[$field] = uint($values[$field] ?? '');
        if ($data[$field] === null) {
            $errors[$field] = 'Sélectionnez une valeur valide.';
        }
    }
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
    $matricule = $values['responsable_matricule'] ?? '';
    if (!mb_check_encoding($matricule, 'UTF-8') || mb_strlen($matricule, 'UTF-8') > 60
        || preg_match('/^[\s\p{Z}]*$/uD', $matricule)) {
        $errors['responsable_matricule'] = 'Sélectionnez un responsable (matricule de 60 caractères maximum).';
    }
    $data['responsable_matricule'] = $matricule; // Conserver zéros, espaces et contenu, sans troncature.
    $deadline = $values['date_echeance'] ?? '';
    $data['date_echeance'] = null;
    if ($deadline !== '') {
        $validFormat = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $deadline);
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
    if ($errors) {
        throw new FormError($errors);
    }
    return $data;
}

function new_submission(array $user, array $config): string
{
    $nonce = bin2hex(random_bytes(32));
    return $nonce . ':' . hash_hmac('sha256', 'nc|' . $user['matricule'] . '|' . $nonce, $config['secret']);
}

function submission_key(string $token, string $actor, array $config): string
{
    if (!preg_match('/^([a-f0-9]{64}):([a-f0-9]{64})$/D', $token, $parts)
        || !hash_equals(hash_hmac('sha256', 'nc|' . $actor . '|' . $parts[1], $config['secret']), $parts[2])) {
        throw new FormError(['form' => "La clé d'enregistrement est invalide. Ouvrez un nouveau formulaire."], 400);
    }
    return hash('sha256', $token);
}

function create_nc(PDO $pdo, array $config, array $user, array $values, string $token, ?string $address): array
{
    if (!can_create($user, $config)) {
        throw new FormError(['form' => "Vous n'avez pas le droit de créer une non-conformité."], 403);
    }
    if (!$config['demo'] && !$config['initial_rules_confirmed']) {
        throw new FormError(['form' => 'La création attend la confirmation des règles de saisie initiale.'], 403);
    }
    $key = submission_key($token, $user['matricule'], $config);
    $content = array_intersect_key($values, array_flip(NC_FIELDS));
    ksort($content);
    $fingerprint = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $lock = 'nc:' . substr(hash('sha256', $config['mysql_database'] . $key), 0, 60);
    $locked = false;
    try {
        $locked = (int) query($pdo, 'SELECT GET_LOCK(?, 10)', [$lock])->fetchColumn() === 1;
        if (!$locked) {
            throw new FormError(['form' => 'Cet enregistrement est en cours. Réessayez avec le même formulaire.'], 409);
        }
        $pdo->beginTransaction();
        $currentUser = load_user($pdo, $user['matricule'], true);
        if (!$currentUser || !can_create($currentUser, $config)) {
            throw new FormError(['form' => 'Votre compte ou vos permissions ne permettent plus cet enregistrement.'], 403);
        }
        $previous = query($pdo, "SELECT entite_id, nouvelles_valeurs FROM journal_audit
            WHERE acteur_matricule=? AND entite='non_conformites' AND operation='CREATION'
            AND JSON_UNQUOTE(JSON_EXTRACT(nouvelles_valeurs, '$._submission.key'))=?
            ORDER BY id LIMIT 1", [$user['matricule'], $key])->fetch();
        if ($previous) {
            $snapshot = json_decode($previous['nouvelles_valeurs'], true, 512, JSON_THROW_ON_ERROR);
            if ($snapshot['_submission']['fingerprint'] !== $fingerprint) {
                throw new FormError(['form' => 'Ce formulaire a déjà enregistré un autre contenu. Ouvrez une nouvelle déclaration.'], 409);
            }
            $pdo->rollBack();
            return [(int) $previous['entite_id'], true];
        }
        // Un succès d'hier reste récupérable avant de revalider l'échéance.
        $today = business_today($config);
        $data = validate_nc($values, $today, $config['deadline_required']);
        $errors = [];
        foreach (['processus_id' => 'processus', 'type_nc_id' => 'types_nc', 'nature_service_id' => 'natures_service'] as $field => $table) {
            if (!query($pdo, "SELECT id FROM $table WHERE id=? AND actif=TRUE FOR SHARE", [$data[$field]])->fetch()) {
                $errors[$field] = "Cette valeur n'existe plus ou est inactive.";
            }
        }
        [$where, $parameters] = assignment_sql($currentUser, $config, $data['processus_id']);
        if (!query($pdo, "SELECT p.matricule FROM pers p WHERE p.matricule=? AND $where FOR SHARE",
            [$data['responsable_matricule'], ...$parameters])->fetch()) {
            $errors['responsable_matricule'] = "Ce responsable n'existe pas ou n'est pas autorisé pour ce dossier.";
        }
        if ($errors) {
            throw new FormError($errors);
        }
        $statement = query($pdo, 'CALL generer_reference(?, ?, ?, @anrt_reference)', ['NC', $data['processus_id'], $today]);
        while ($statement->nextRowset()) {
            // Vider les résultats de CALL avant la prochaine requête PDO.
        }
        $statement->closeCursor();
        $number = query($pdo, "SELECT derniere_valeur FROM compteurs_reference
            WHERE type_document='NC' AND processus_id=? AND annee=?", [$data['processus_id'], (int) substr($today, 0, 4)])->fetchColumn();
        if ((int) $number > 999) {
            throw new FormError(['form' => "La limite annuelle de 999 références pour ce processus est atteinte. Contactez l'administrateur."], 409);
        }
        $reference = query($pdo, 'SELECT @anrt_reference')->fetchColumn();
        if (!is_string($reference) || $reference === '' || mb_strlen($reference, 'UTF-8') > 50) {
            throw new FormError(['form' => "La référence générée est invalide. Contactez l'administrateur."], 409);
        }
        query($pdo, "INSERT INTO non_conformites
            (reference, processus_id, type_nc_id, nature_service_id, enregistree_par_matricule,
             description, traitement, responsable_matricule, date_creation, date_echeance, statut_code)
            VALUES (?,?,?,?,?,?,?,?,?,?,'OUVERTE')",
            [$reference, $data['processus_id'], $data['type_nc_id'], $data['nature_service_id'], $user['matricule'],
             $data['description'], $data['traitement'], $data['responsable_matricule'], $today, $data['date_echeance']]);
        $id = (int) $pdo->lastInsertId();
        $snapshot = query($pdo, 'SELECT * FROM non_conformites WHERE id=?', [$id])->fetch();
        $snapshot['_submission'] = ['key' => $key, 'fingerprint' => $fingerprint];
        query($pdo, "INSERT INTO journal_audit
            (acteur_matricule, operation, entite, entite_id, nouvelles_valeurs, adresse_ip)
            VALUES (?,'CREATION','non_conformites',?,?,?)",
            [$user['matricule'], (string) $id, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $address]);
        $pdo->commit();
        return [$id, false];
    } catch (Throwable $error) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (PDOException) {
            // Après une perte de connexion, le résultat du commit peut être inconnu.
        }
        throw $error;
    } finally {
        if ($locked) {
            try {
                query($pdo, 'SELECT RELEASE_LOCK(?)', [$lock])->closeCursor();
            } catch (PDOException) {
                // La fermeture de cette connexion non persistante libère aussi le verrou.
            }
        }
    }
}

function nc_choices(PDO $pdo, array $config, array $user, bool $forList = false): array
{
    $tables = $forList ? ['processus' => 'id, code, nom']
        : ['processus' => 'id, code, nom', 'types_nc' => 'id, libelle', 'natures_service' => 'id, libelle'];
    $result = [];
    foreach ($tables as $table => $columns) {
        $condition = $forList ? '1=1' : 'actif=TRUE';
        $result[$table] = query($pdo, "SELECT $columns FROM $table WHERE $condition ORDER BY id")->fetchAll();
    }
    if ($forList) {
        $result['statuts'] = query($pdo, 'SELECT code, libelle FROM statuts ORDER BY code')->fetchAll();
    } else {
        [$where, $parameters] = assignment_sql($user, $config);
        $result['responsables'] = query($pdo, "SELECT p.matricule, p.nom_prenom FROM pers p WHERE $where ORDER BY p.nom_prenom, p.matricule", $parameters)->fetchAll();
    }
    return $result;
}

function list_nc(PDO $pdo, array $config, array $user, array $filters, int $requestedPage): array
{
    [$where, $parameters] = scope_sql($user, $config);
    $clauses = [$where];
    foreach (['reference' => 50, 'responsable_matricule' => 60] as $field => $maximum) {
        if (mb_strlen($filters[$field] ?? '', 'UTF-8') > $maximum) {
            throw new FormError([$field => "Ce filtre est limité à $maximum caractères."], 400);
        }
    }
    if (($filters['reference'] ?? '') !== '') {
        $clauses[] = 'LOCATE(?, nc.reference)>0';
        $parameters[] = $filters['reference'];
    }
    if (($filters['processus_id'] ?? '') !== '') {
        $process = uint($filters['processus_id']);
        if ($process === null) {
            throw new FormError(['processus_id' => 'Processus invalide.'], 400);
        }
        $clauses[] = 'nc.processus_id=?';
        $parameters[] = $process;
    }
    if (($filters['statut_code'] ?? '') !== '') {
        if (!in_array($filters['statut_code'], ['OUVERTE', 'AJOURNEE', 'CLOTUREE', 'ANNULEE'], true)) {
            throw new FormError(['statut_code' => 'Statut invalide.'], 400);
        }
        $clauses[] = 'nc.statut_code=?';
        $parameters[] = $filters['statut_code'];
    }
    if (($filters['responsable_matricule'] ?? '') !== '') {
        $clauses[] = 'nc.responsable_matricule=?';
        $parameters[] = $filters['responsable_matricule'];
    }
    $predicate = implode(' AND ', $clauses);
    $pdo->beginTransaction();
    try {
        $total = (int) query($pdo, "SELECT COUNT(*) FROM non_conformites nc WHERE $predicate", $parameters)->fetchColumn();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($requestedPage, $pages);
        $rows = query($pdo, "SELECT nc.id, nc.reference, nc.date_creation, nc.date_echeance,
            nc.statut_code, pr.nom AS processus, t.libelle AS type_nc,
            p.nom_prenom AS responsable, nc.responsable_matricule
            FROM non_conformites nc JOIN processus pr ON pr.id=nc.processus_id
            JOIN types_nc t ON t.id=nc.type_nc_id JOIN pers p ON p.matricule=nc.responsable_matricule
            WHERE $predicate ORDER BY nc.created_at DESC, nc.id DESC LIMIT ? OFFSET ?",
            [...$parameters, 20, ($page - 1) * 20])->fetchAll();
        return compact('rows', 'total', 'page', 'pages');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function detail_nc(PDO $pdo, array $config, array $user, int $id): array
{
    [$where, $parameters] = scope_sql($user, $config);
    $nc = query($pdo, "SELECT nc.*, pr.code AS processus_code, pr.nom AS processus,
        t.libelle AS type_nc, n.libelle AS nature_service, s.libelle AS statut,
        responsible.nom_prenom AS responsable, declarant.nom_prenom AS declarant
        FROM non_conformites nc JOIN processus pr ON pr.id=nc.processus_id
        JOIN types_nc t ON t.id=nc.type_nc_id JOIN natures_service n ON n.id=nc.nature_service_id
        JOIN statuts s ON s.code=nc.statut_code JOIN pers responsible ON responsible.matricule=nc.responsable_matricule
        JOIN pers declarant ON declarant.matricule=nc.enregistree_par_matricule
        WHERE nc.id=? AND $where", [$id, ...$parameters])->fetch();
    if (!$nc) {
        return [null, []];
    }
    [$auditWhere, $auditParameters] = scope_sql($user, $config, 'audit');
    $mayAudit = query($pdo, "SELECT nc.id FROM non_conformites nc WHERE nc.id=? AND $auditWhere", [$id, ...$auditParameters])->fetch();
    $audit = $mayAudit ? query($pdo, "SELECT j.operation, j.created_at, j.acteur_matricule, p.nom_prenom AS acteur
        FROM journal_audit j LEFT JOIN pers p ON p.matricule=j.acteur_matricule
        WHERE j.entite='non_conformites' AND j.entite_id=? ORDER BY j.created_at, j.id", [(string) $id])->fetchAll() : [];
    return [$nc, $audit];
}

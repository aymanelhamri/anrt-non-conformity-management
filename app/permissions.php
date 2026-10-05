<?php
declare(strict_types=1);

function demo_policy(): array
{
    // Recette technique seulement ; aucun droit confirmé par cette démonstration.
    return [
        'ADMINISTRATEUR' => ['create' => true, 'read' => ['all'], 'audit' => ['all'], 'assign' => 'all'],
        'RESPONSABLE' => ['create' => true, 'read' => ['own', 'assigned'], 'audit' => ['own', 'assigned'], 'assign' => 'self'],
        'PILOTE_PROCESSUS' => ['read' => ['process'], 'audit' => ['process']],
        'CONSULTATION' => ['read' => ['own', 'assigned']],
    ];
}

function validate_policy(mixed $policy): void
{
    if (!is_array($policy)) {
        throw new RuntimeException('Les permissions doivent être un objet JSON.');
    }
    foreach ($policy as $profile => $rule) {
        if (!is_string($profile) || strlen($profile) > 30 || !is_array($rule)
            || array_diff(array_keys($rule), ['create', 'read', 'audit', 'assign'])) {
            throw new RuntimeException('Profil ou permission invalide.');
        }
        if (array_key_exists('create', $rule) && !is_bool($rule['create'])) {
            throw new RuntimeException('create doit être un booléen.');
        }
        foreach (['read', 'audit'] as $operation) {
            if (isset($rule[$operation])) {
                if (!is_array($rule[$operation]) || !array_is_list($rule[$operation])) {
                    throw new RuntimeException('Les périmètres doivent être une liste.');
                }
                foreach ($rule[$operation] as $scope) {
                    if (!in_array($scope, ['own', 'assigned', 'process', 'all'], true)) {
                        throw new RuntimeException('Périmètre inconnu.');
                    }
                }
            }
        }
        if (!in_array($rule['assign'] ?? null, [null, 'self', 'all', 'process'], true)) {
            throw new RuntimeException("Périmètre d'affectation inconnu.");
        }
        if (($rule['create'] ?? false) && !array_intersect(['own', 'all'], $rule['read'] ?? [])) {
            throw new RuntimeException('La création requiert la lecture des dossiers déclarés.');
        }
    }
}

function user_rules(array $user, array $config): array
{
    $rules = [];
    foreach ($user['profiles'] as $profile) {
        if (isset($config['permissions'][$profile])) {
            $rules[] = $config['permissions'][$profile];
        }
    }
    return $rules;
}

function can_create(array $user, array $config): bool
{
    foreach (user_rules($user, $config) as $rule) {
        if (($rule['create'] ?? false) === true) {
            return true;
        }
    }
    return false;
}

function scope_sql(array $user, array $config, string $operation = 'read'): array
{
    $scopes = [];
    foreach (user_rules($user, $config) as $rule) {
        $scopes = array_merge($scopes, $rule[$operation] ?? []);
    }
    if (in_array('all', $scopes, true)) {
        return ['1=1', []];
    }
    $clauses = $parameters = [];
    foreach (['own' => 'enregistree_par_matricule', 'assigned' => 'responsable_matricule'] as $scope => $column) {
        if (in_array($scope, $scopes, true)) {
            $clauses[] = "nc.$column = ?";
            $parameters[] = $user['matricule'];
        }
    }
    if (in_array('process', $scopes, true)) {
        $clauses[] = 'EXISTS (SELECT 1 FROM processus_responsable pr_scope WHERE pr_scope.processus_id=nc.processus_id AND pr_scope.matricule=?)';
        $parameters[] = $user['matricule'];
    }
    return [$clauses ? '(' . implode(' OR ', $clauses) . ')' : '0=1', $parameters];
}

function assignment_sql(array $user, array $config, ?int $processId = null): array
{
    $scopes = array_column(user_rules($user, $config), 'assign');
    if (in_array('all', $scopes, true)) {
        return ['1=1', []];
    }
    $clauses = $parameters = [];
    if (in_array('self', $scopes, true)) {
        $clauses[] = 'p.matricule=?';
        $parameters[] = $user['matricule'];
    }
    if (in_array('process', $scopes, true)) {
        $clauses[] = 'EXISTS (SELECT 1 FROM processus_responsable target
            JOIN processus_responsable mine ON mine.processus_id=target.processus_id
            WHERE target.matricule=p.matricule AND mine.matricule=?'
            . ($processId !== null ? ' AND mine.processus_id=?' : '') . ')';
        $parameters[] = $user['matricule'];
        if ($processId !== null) {
            $parameters[] = $processId;
        }
    }
    return [$clauses ? '(' . implode(' OR ', $clauses) . ')' : '0=1', $parameters];
}

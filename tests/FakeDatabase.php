<?php
declare(strict_types=1);

/** Connexion instrumentée : vérifie les appels PDO, jamais l'atomicité MySQL. */
class FakeDatabase extends PDO
{
    public array $calls = [];
    public array $transactions = [];
    public bool $active = false;
    public bool $failAudit = false;
    public bool $loseCommitResponse = false;
    public bool $accountValid = true;
    public bool $referencesValid = true;
    public int $number = 1;
    public ?array $previous = null;
    public ?array $audit = null;
    public ?array $insert = null;
    public array $user = ['matricule' => '00017', 'nom_prenom' => 'Compte test'];
    public array $profiles = ['RESPONSABLE'];
    public ?string $store;

    public function __construct(?string $store = null)
    {
        $this->store = $store;
        if ($store && is_file($store)) {
            $state = json_decode(file_get_contents($store), true, 512, JSON_THROW_ON_ERROR);
            $this->insert = $state['insert'];
            $this->audit = $state['audit'];
            $this->previous = ['entite_id' => '41', 'nouvelles_valeurs' => $this->audit[2]];
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->transactions[] = 'begin';
        return $this->active = true;
    }

    public function commit(): bool
    {
        $this->transactions[] = 'commit';
        $this->active = false;
        if ($this->store) {
            file_put_contents($this->store, json_encode(['insert' => $this->insert, 'audit' => $this->audit], JSON_THROW_ON_ERROR));
        }
        if ($this->loseCommitResponse) {
            throw new PDOException('Réponse perdue après commit');
        }
        return true;
    }

    public function rollBack(): bool
    {
        $this->transactions[] = 'rollback';
        $this->active = false;
        return true;
    }

    public function inTransaction(): bool { return $this->active; }
    public function lastInsertId(?string $name = null): string|false { return '41'; }

    public function results(string $sql, array $values): array
    {
        $this->calls[] = [$sql, $values];
        if (str_contains($sql, 'GET_LOCK')) { return [[1]]; }
        if (str_contains($sql, 'RELEASE_LOCK')) { return [[1]]; }
        if (str_contains($sql, 'SELECT passe')) { return [['passe' => password_hash('Test-secret', PASSWORD_BCRYPT)]]; }
        if (str_contains($sql, 'FROM acces a')) { return $this->accountValid ? [$this->user] : []; }
        if (str_contains($sql, 'FROM acces_profil')) { return array_map(fn(string $p): array => ['profil_code' => $p], $this->profiles); }
        if (str_contains($sql, 'SELECT entite_id')) {
            $key = $this->previous ? json_decode($this->previous['nouvelles_valeurs'], true)['_submission']['key'] : null;
            return $key === $values[1] ? [$this->previous] : [];
        }
        if (str_contains($sql, 'SELECT id, code, nom')) { return [['id' => 1, 'code' => 'PO', 'nom' => 'Politique']]; }
        if (str_contains($sql, 'SELECT id, libelle')) { return [['id' => 1, 'libelle' => 'Autre']]; }
        if (str_contains($sql, 'SELECT code, libelle FROM statuts')) { return [['code' => 'OUVERTE', 'libelle' => 'Ouverte']]; }
        if (str_contains($sql, 'SELECT id FROM') || str_contains($sql, 'SELECT p.matricule')) { return $this->referencesValid ? [['id' => 1, 'matricule' => '00017', 'nom_prenom' => 'Compte test']] : []; }
        if (str_contains($sql, 'CALL generer_reference')) { return []; }
        if (str_contains($sql, 'SELECT derniere_valeur')) { return [[$this->number]]; }
        if (str_contains($sql, 'SELECT @anrt_reference')) { return [['NC-PO-001-26']]; }
        if (str_contains($sql, 'INSERT INTO non_conformites')) { $this->insert = $values; return []; }
        if (str_contains($sql, 'SELECT * FROM non_conformites')) { return [['id' => 41, 'reference' => 'NC-PO-001-26', 'description' => $this->insert[5]]]; }
        if (str_contains($sql, 'INSERT INTO journal_audit')) {
            if ($this->failAudit) { throw new PDOException('Erreur audit injectée'); }
            $this->audit = $values;
            return [];
        }
        if (str_contains($sql, 'SELECT COUNT(*) FROM non_conformites')) { return [[$this->insert ? 1 : 0]]; }
        if (str_contains($sql, 'FROM non_conformites nc')) {
            if (!$this->insert || str_contains($sql, '0=1')) { return []; }
            if (str_contains($sql, 'nc.id=?') && $values[0] !== 41) { return []; }
            if (str_contains($sql, 'SELECT nc.id FROM')) { return [['id' => 41]]; }
            return [[
                'id' => 41, 'reference' => $this->insert[0], 'processus_id' => 1, 'processus_code' => 'PO',
                'processus' => 'Politique', 'type_nc' => 'Autre', 'nature_service' => 'Autre',
                'responsable' => 'Compte test', 'responsable_matricule' => $this->insert[7],
                'declarant' => 'Compte test', 'enregistree_par_matricule' => $this->insert[4],
                'description' => $this->insert[5], 'traitement' => $this->insert[6],
                'date_creation' => $this->insert[8], 'date_echeance' => $this->insert[9],
                'statut_code' => 'OUVERTE', 'statut' => 'Ouverte', 'updated_at' => '2026-10-05 12:00:00',
            ]];
        }
        if (str_contains($sql, 'SELECT j.operation')) { return [['operation' => 'CREATION', 'created_at' => '2026-10-05 12:00:00', 'acteur_matricule' => '00017', 'acteur' => 'Compte test']]; }
        throw new RuntimeException('SQL non pris en charge par le double de test.');
    }
}

class FakeStatement extends PDOStatement
{
    private array $parameters = [];
    private array $rows = [];
    public function __construct(private FakeDatabase $pdo, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->parameters[$param - 1] = $value;
        return true;
    }
    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->results($this->sql, $params ?? array_values($this->parameters));
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $mode === PDO::FETCH_COLUMN ? array_map(fn(array $r): mixed => array_values($r)[0], $this->rows) : $this->rows;
    }
    public function nextRowset(): bool { return false; }
    public function closeCursor(): bool { return true; }
}

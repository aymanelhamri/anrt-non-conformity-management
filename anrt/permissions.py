from flask import current_app


# Politique technique de recette uniquement : aucune correspondance avec les
# trois profils envisagés. CONSULTATION ne peut jamais créer dans cette démo.
DEMO_POLICY = {
    "ADMINISTRATEUR": {"create": True, "read": ["all"], "audit": ["all"], "assign": "all"},
    "RESPONSABLE": {"create": True, "read": ["own", "assigned"], "audit": ["own", "assigned"], "assign": "self"},
    "PILOTE_PROCESSUS": {"read": ["process"], "audit": ["process"]},
    "CONSULTATION": {"read": ["own", "assigned"]},
}
SCOPES = {"own", "assigned", "process", "all"}


def validate_policy(policy):
    if not isinstance(policy, dict):
        raise ValueError("Les permissions doivent être un objet JSON")
    for profile, rule in policy.items():
        if not isinstance(profile, str) or len(profile) > 30 or not isinstance(rule, dict):
            raise ValueError("Profil de permissions invalide")
        if set(rule) - {"create", "read", "audit", "assign"}:
            raise ValueError("Permission inconnue")
        if "create" in rule and type(rule["create"]) is not bool:
            raise ValueError("create doit être un booléen")
        for operation in ("read", "audit"):
            if operation in rule and (not isinstance(rule[operation], list) or any(s not in SCOPES for s in rule[operation])):
                raise ValueError("Périmètre inconnu")
        if rule.get("assign") not in (None, "self", "all", "process"):
            raise ValueError("Périmètre d'affectation inconnu")
        if rule.get("create") and not ({"own", "all"} & set(rule.get("read", []))):
            raise ValueError("La création requiert la lecture des dossiers déclarés")


def rules(user):
    policy = current_app.config["PERMISSIONS"]
    return [policy[p] for p in user["profiles"] if p in policy]


def can_create(user):
    return any(r.get("create") is True for r in rules(user))


def scope_sql(user, operation="read", alias="nc"):
    """Même prédicat paramétré pour la liste et les accès directs."""
    scopes = {s for r in rules(user) for s in r.get(operation, [])}
    if "all" in scopes:
        return "1=1", []
    clauses, params = [], []
    for scope, column in (("own", "enregistree_par_matricule"), ("assigned", "responsable_matricule")):
        if scope in scopes:
            clauses.append(f"{alias}.{column} = %s")
            params.append(user["matricule"])
    if "process" in scopes:
        clauses.append(f"EXISTS (SELECT 1 FROM processus_responsable pr_scope WHERE pr_scope.processus_id={alias}.processus_id AND pr_scope.matricule=%s)")
        params.append(user["matricule"])
    return "(" + " OR ".join(clauses) + ")" if clauses else "0=1", params


def assignment_sql(user, process_id=None):
    scopes = {r.get("assign") for r in rules(user)}
    if "all" in scopes:
        return "1=1", []
    clauses, params = [], []
    if "self" in scopes:
        clauses.append("p.matricule=%s")
        params.append(user["matricule"])
    if "process" in scopes:
        clauses.append("EXISTS (SELECT 1 FROM processus_responsable target JOIN processus_responsable mine ON mine.processus_id=target.processus_id WHERE target.matricule=p.matricule AND mine.matricule=%s" + (" AND mine.processus_id=%s" if process_id else "") + ")")
        params.append(user["matricule"])
        if process_id:
            params.append(process_id)
    return "(" + " OR ".join(clauses) + ")" if clauses else "0=1", params

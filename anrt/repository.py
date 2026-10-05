import math

from .db import cursor
from .permissions import assignment_sql, scope_sql
from .validation import FormError, uint


DETAIL_SELECT = """SELECT nc.*, pr.code AS processus_code, pr.nom AS processus,
    t.libelle AS type_nc, n.libelle AS nature_service, s.libelle AS statut,
    responsible.nom_prenom AS responsable, declarant.nom_prenom AS declarant
    FROM non_conformites nc
    JOIN processus pr ON pr.id=nc.processus_id
    JOIN types_nc t ON t.id=nc.type_nc_id
    JOIN natures_service n ON n.id=nc.nature_service_id
    JOIN statuts s ON s.code=nc.statut_code
    JOIN pers responsible ON responsible.matricule=nc.responsable_matricule
    JOIN pers declarant ON declarant.matricule=nc.enregistree_par_matricule"""


def choices(user, include_inactive=False):
    with cursor() as cur:
        result = {}
        for table, columns in (("processus", "id, code, nom"), ("types_nc", "id, libelle"), ("natures_service", "id, libelle")):
            condition = "1=1" if include_inactive and table == "processus" else "actif=TRUE"
            cur.execute(f"SELECT {columns} FROM {table} WHERE {condition} ORDER BY id")
            result[table] = cur.fetchall()
        where, args = assignment_sql(user)
        cur.execute(f"SELECT p.matricule, p.nom_prenom FROM pers p WHERE {where} ORDER BY p.nom_prenom, p.matricule", args)
        result["responsables"] = cur.fetchall()
        cur.execute("SELECT code, libelle FROM statuts ORDER BY code")
        result["statuts"] = cur.fetchall()
        return result


def list_nc(user, filters, requested_page):
    where, params = scope_sql(user)
    clauses = [where]
    reference = filters.get("reference", "")
    if len(reference) > 50:
        raise FormError({"reference": "La référence est limitée à 50 caractères."}, 400)
    if reference:
        # Recherche littérale : % et _ ne deviennent pas des jokers.
        clauses.append("LOCATE(%s, nc.reference)>0")
        params.append(reference)
    process = filters.get("processus_id", "")
    if process:
        if uint(process) is None:
            raise FormError({"processus_id": "Processus invalide."}, 400)
        clauses.append("nc.processus_id=%s")
        params.append(uint(process))
    status = filters.get("statut_code", "")
    if status:
        if status not in {"OUVERTE", "AJOURNEE", "CLOTUREE", "ANNULEE"}:
            raise FormError({"statut_code": "Statut invalide."}, 400)
        clauses.append("nc.statut_code=%s")
        params.append(status)
    responsible = filters.get("responsable_matricule", "")
    if responsible:
        if len(responsible) > 60:
            raise FormError({"responsable_matricule": "Matricule limité à 60 caractères."}, 400)
        clauses.append("nc.responsable_matricule=%s")
        params.append(responsible)
    predicate = " AND ".join(clauses)
    with cursor() as cur:
        # Un même snapshot pour le total et les lignes affichées.
        cur.connection.begin()
        cur.execute(f"SELECT COUNT(*) AS total FROM non_conformites nc WHERE {predicate}", params)
        total = cur.fetchone()["total"]
        pages = max(1, math.ceil(total / 20))
        page = min(requested_page, pages)
        cur.execute(f"""SELECT nc.id, nc.reference, nc.date_creation, nc.date_echeance,
            nc.statut_code, pr.nom AS processus, t.libelle AS type_nc,
            p.nom_prenom AS responsable, nc.responsable_matricule
            FROM non_conformites nc JOIN processus pr ON pr.id=nc.processus_id
            JOIN types_nc t ON t.id=nc.type_nc_id
            JOIN pers p ON p.matricule=nc.responsable_matricule
            WHERE {predicate} ORDER BY nc.created_at DESC, nc.id DESC LIMIT %s OFFSET %s""",
            params + [20, (page - 1) * 20])
        rows = cur.fetchall()
        cur.connection.rollback()
    return {"rows": rows, "total": total, "page": page, "pages": pages}


def detail_nc(user, nc_id):
    where, params = scope_sql(user)
    with cursor() as cur:
        cur.execute(DETAIL_SELECT + f" WHERE nc.id=%s AND {where}", [nc_id] + params)
        row = cur.fetchone()
        if row is None:
            return None, []
        audit_where, audit_params = scope_sql(user, "audit")
        cur.execute(f"SELECT nc.id FROM non_conformites nc WHERE nc.id=%s AND {audit_where}", [nc_id] + audit_params)
        audit = []
        if cur.fetchone():
            cur.execute("""SELECT j.operation, j.created_at, j.acteur_matricule,
                p.nom_prenom AS acteur FROM journal_audit j
                LEFT JOIN pers p ON p.matricule=j.acteur_matricule
                WHERE j.entite='non_conformites' AND j.entite_id=%s
                ORDER BY j.created_at, j.id""", (str(nc_id),))
            audit = cur.fetchall()
        return row, audit

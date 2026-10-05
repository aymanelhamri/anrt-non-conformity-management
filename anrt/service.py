import hashlib
import json
import secrets
from datetime import datetime
from zoneinfo import ZoneInfo

from flask import current_app
from itsdangerous import BadSignature, URLSafeSerializer
import pymysql

from .auth import load_user
from .db import connect
from .permissions import assignment_sql, can_create
from .validation import FIELDS, FormError, validate_form


def serializer():
    return URLSafeSerializer(current_app.config["SECRET_KEY"], salt="anrt-nc-create-v1")


def new_submission(user):
    return serializer().dumps({"actor": user["matricule"], "nonce": secrets.token_hex(32)})


def submission_key(token, actor):
    try:
        data = serializer().loads(token)
        if data.get("actor") != actor or len(data.get("nonce", "")) != 64:
            raise BadSignature("Acteur invalide")
    except (BadSignature, AttributeError, TypeError):
        raise FormError({"form": "La clé d'enregistrement est invalide. Ouvrez un nouveau formulaire."}, 400) from None
    return hashlib.sha256(token.encode()).hexdigest()


def business_today():
    return datetime.now(ZoneInfo(current_app.config["APP_TIMEZONE"])).date()


def create_nc(user, values, token, address):
    if not can_create(user):
        raise FormError({"form": "Vous n'avez pas le droit de créer une non-conformité."}, 403)
    if not (current_app.config["DEMO_MODE"] or current_app.config["NC_INITIAL_RULES_CONFIRMED"]):
        raise FormError({"form": "La création attend la confirmation des règles de saisie initiale."}, 403)
    key = submission_key(token, user["matricule"])
    fingerprint = hashlib.sha256(json.dumps({f: values.get(f, "") for f in sorted(FIELDS)}, ensure_ascii=False, sort_keys=True).encode()).hexdigest()
    # < 64 caractères ; une même clé est sérialisée sur le serveur MySQL,
    # y compris entre plusieurs workers et après un redémarrage de l'application.
    lock_name = "nc:" + hashlib.sha256((current_app.config["MYSQL_DATABASE"] + key).encode()).hexdigest()[:60]
    conn = connect()
    locked = False
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT GET_LOCK(%s, 10) AS acquired", (lock_name,))
            locked = cur.fetchone()["acquired"] == 1
            if not locked:
                raise FormError({"form": "Cet enregistrement est en cours. Réessayez avec le même formulaire."}, 409)
            conn.begin()
            current_user = load_user(user["matricule"], cur, lock=True)
            if not current_user or not can_create(current_user):
                raise FormError({"form": "Votre compte ou vos permissions ne permettent plus cet enregistrement."}, 403)
            cur.execute("""SELECT entite_id, nouvelles_valeurs FROM journal_audit
                WHERE acteur_matricule=%s AND entite='non_conformites' AND operation='CREATION'
                AND JSON_UNQUOTE(JSON_EXTRACT(nouvelles_valeurs, '$._submission.key'))=%s
                ORDER BY id LIMIT 1""", (user["matricule"], key))
            previous = cur.fetchone()
            if previous:
                snapshot = previous["nouvelles_valeurs"]
                if isinstance(snapshot, str):
                    snapshot = json.loads(snapshot)
                if snapshot["_submission"]["fingerprint"] != fingerprint:
                    raise FormError({"form": "Ce formulaire a déjà enregistré un autre contenu. Ouvrez une nouvelle déclaration."}, 409)
                conn.rollback()
                return int(previous["entite_id"]), True
            # Le réessai est reconnu avant validation de l'échéance : un succès
            # d'hier reste récupérable aujourd'hui avec le même contenu.
            today = business_today()
            data = validate_form(values, today, current_app.config["NC_DEADLINE_REQUIRED"])
            errors = {}
            for field, table in (("processus_id", "processus"), ("type_nc_id", "types_nc"), ("nature_service_id", "natures_service")):
                cur.execute(f"SELECT id FROM {table} WHERE id=%s AND actif=TRUE FOR SHARE", (data[field],))
                if not cur.fetchone():
                    errors[field] = "Cette valeur n'existe plus ou est inactive."
            where, args = assignment_sql(current_user, data["processus_id"])
            cur.execute(f"SELECT p.matricule FROM pers p WHERE p.matricule=%s AND {where} FOR SHARE", [data["responsable_matricule"]] + args)
            if not cur.fetchone():
                errors["responsable_matricule"] = "Ce responsable n'existe pas ou n'est pas autorisé pour ce dossier."
            if errors:
                raise FormError(errors)
            cur.execute("CALL generer_reference(%s, %s, %s, @anrt_reference)", ("NC", data["processus_id"], today))
            while cur.nextset():
                pass
            # La procédure a verrouillé la ligne compteur jusqu'au commit.
            # LPAD(1000,3,'0') = '100' : refuser et annuler, sans collision.
            cur.execute("""SELECT derniere_valeur FROM compteurs_reference
                WHERE type_document='NC' AND processus_id=%s AND annee=%s""", (data["processus_id"], today.year))
            number = cur.fetchone()["derniere_valeur"]
            if number > 999:
                raise FormError({"form": "La limite annuelle de 999 références pour ce processus est atteinte. Contactez l'administrateur."}, 409)
            cur.execute("SELECT @anrt_reference AS reference")
            reference = cur.fetchone()["reference"]
            if not reference or len(reference) > 50:
                raise FormError({"form": "La référence générée est invalide. Contactez l'administrateur."}, 409)
            cur.execute("""INSERT INTO non_conformites
                (reference, processus_id, type_nc_id, nature_service_id,
                enregistree_par_matricule, description, traitement, responsable_matricule,
                date_creation, date_echeance, statut_code)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'OUVERTE')""",
                (reference, data["processus_id"], data["type_nc_id"], data["nature_service_id"],
                 user["matricule"], data["description"], data["traitement"], data["responsable_matricule"], today, data["date_echeance"]))
            nc_id = cur.lastrowid
            cur.execute("SELECT * FROM non_conformites WHERE id=%s", (nc_id,))
            snapshot = cur.fetchone()
            snapshot["_submission"] = {"key": key, "fingerprint": fingerprint}
            cur.execute("""INSERT INTO journal_audit
                (acteur_matricule, operation, entite, entite_id, nouvelles_valeurs, adresse_ip)
                VALUES (%s,'CREATION','non_conformites',%s,%s,%s)""",
                (user["matricule"], str(nc_id), json.dumps(snapshot, ensure_ascii=False, default=str), address))
            conn.commit()
            return nc_id, False
    except Exception:
        try:
            conn.rollback()
        except pymysql.MySQLError:
            pass
        raise
    finally:
        if locked:
            try:
                with conn.cursor() as cur:
                    cur.execute("SELECT RELEASE_LOCK(%s)", (lock_name,))
            except pymysql.MySQLError:
                pass  # La fermeture de la connexion libère aussi le verrou.
        conn.close()

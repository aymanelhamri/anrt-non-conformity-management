"""Recette MySQL explicite : chaque test crée une base neuve au nom aléatoire.

Aucune base existante n'est utilisée/vidée/supprimée. Les bases créées sont
conservées pour inspection ; leur suppression éventuelle reste manuelle.
"""
import json
import os
import re
import threading
import uuid
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pymysql
import pytest

from anrt import create_app
from anrt.auth import HASHER, authenticate, load_user
from anrt.db import cursor
from anrt.permissions import DEMO_POLICY
from anrt.repository import detail_nc, list_nc
from anrt.service import business_today, create_nc, new_submission
from anrt.validation import FormError

pytestmark = [pytest.mark.mysql, pytest.mark.skipif(os.getenv("TEST_MYSQL") != "1", reason="MySQL de recette non activé (TEST_MYSQL=1)")]
ROOT = Path(__file__).resolve().parents[1]


def execute_script(cur, path):
    source = path.read_text(encoding="utf-8")
    source = re.sub(r"CREATE DATABASE IF NOT EXISTS anrt_qualite\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci;", "", source)
    source = re.sub(r"USE anrt_qualite;", "", source)
    delimiter, buffer = ";", ""
    for line in source.splitlines():
        if line.lstrip().startswith("--") or not line.strip():
            continue
        if line.startswith("DELIMITER "):
            delimiter = line.split()[1]
            continue
        buffer += line + "\n"
        if buffer.rstrip().endswith(delimiter):
            cur.execute(buffer.rstrip()[:-len(delimiter)])
            while cur.nextset():
                pass
            buffer = ""
    assert not buffer.strip(), "SQL de recette non entièrement exécuté"


@pytest.fixture
def mysql_app(tmp_path):
    name = "anrt_recette_" + uuid.uuid4().hex
    connection = pymysql.connect(
        host=os.getenv("TEST_MYSQL_HOST", "127.0.0.1"),
        port=int(os.getenv("TEST_MYSQL_PORT", "3306")),
        user=os.getenv("TEST_MYSQL_USER", "root"),
        password=os.environ["TEST_MYSQL_PASSWORD"], charset="utf8mb4", autocommit=True,
    )
    try:
        with connection.cursor() as cur:
            # Pas IF NOT EXISTS : une collision échoue avant toute écriture.
            cur.execute(f"CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
            cur.execute(f"USE `{name}`")
            for filename in ("001_schema.sql", "002_seed_referentiels.sql", "003_procedures.sql", "004_views.sql"):
                execute_script(cur, ROOT / "database" / filename)
    finally:
        connection.close()
    app = create_app({
        "TESTING": True, "SECRET_KEY": "integration-test-only-not-a-production-secret",
        "SESSION_STORE": tmp_path / "session.sqlite3", "SESSION_COOKIE_SECURE": False,
        "MYSQL_HOST": os.getenv("TEST_MYSQL_HOST", "127.0.0.1"),
        "MYSQL_PORT": int(os.getenv("TEST_MYSQL_PORT", "3306")),
        "MYSQL_USER": os.getenv("TEST_MYSQL_USER", "root"),
        "MYSQL_PASSWORD": os.environ["TEST_MYSQL_PASSWORD"], "MYSQL_DATABASE": name,
        "MYSQL_SSL_CA": None, "DEMO_MODE": True, "PERMISSIONS": DEMO_POLICY,
        "APP_TIMEZONE": "UTC", "NC_DEADLINE_REQUIRED": False,
    })
    print(f"Base de recette créée et conservée : {name}")
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    with app.app_context(), cursor() as cur:
        encoded = HASHER.hash("Recette-uniquement-2026")
        for matricule, start, end in (("00017", now - timedelta(days=1), None),
                                      ("00018", now - timedelta(days=1), None),
                                      ("future", now + timedelta(days=1), None),
                                      ("expired", now - timedelta(days=2), now - timedelta(days=1))):
            cur.execute("INSERT INTO pers (matricule, nom_prenom) VALUES (%s,%s)", (matricule, "Recette " + matricule))
            cur.execute("INSERT INTO acces (matricule, nom, passe, Date_Deb, Date_Fin) VALUES (%s,'nom non unique',%s,%s,%s)", (matricule, encoded, start, end))
            cur.execute("INSERT INTO acces_profil VALUES (%s,'RESPONSABLE')", (matricule,))
    return app


def counts():
    with cursor() as cur:
        results = []
        for table in ("non_conformites", "journal_audit", "compteurs_reference"):
            cur.execute(f"SELECT COUNT(*) AS total FROM {table}")
            results.append(cur.fetchone()["total"])
        return tuple(results)


def test_mysql_accounts_and_revocation(mysql_app):
    with mysql_app.app_context():
        assert authenticate("00017", "Recette-uniquement-2026")["matricule"] == "00017"
        assert authenticate("00017", "bad") is None
        assert authenticate("17", "Recette-uniquement-2026") is None
        assert authenticate("future", "Recette-uniquement-2026") is None
        assert authenticate("expired", "Recette-uniquement-2026") is None
        with cursor() as cur:
            cur.execute("UPDATE acces SET Date_Fin=CURRENT_TIMESTAMP WHERE matricule='00017'")
        assert load_user("00017") is None


def test_mysql_create_audit_and_replay(mysql_app, user, values):
    with mysql_app.app_context():
        token = new_submission(user)
        nc_id, replay = create_nc(user, values, token, "127.0.0.1")
        assert not replay
        assert create_nc(user, values, token, "127.0.0.1") == (nc_id, True)
        assert counts() == (1, 1, 1)
        nc, audit = detail_nc(user, nc_id)
        assert nc["statut_code"] == "OUVERTE" and nc["responsable_matricule"] == "00017"
        assert len(audit) == 1 and audit[0]["acteur_matricule"] == "00017"
        with cursor() as cur:
            cur.execute("SELECT nouvelles_valeurs FROM journal_audit")
            snapshot = json.loads(cur.fetchone()["nouvelles_valeurs"])
            assert snapshot["reference"] == nc["reference"] and snapshot["description"] == values["description"]
            assert "passe" not in snapshot
        with pytest.raises(FormError) as error:
            create_nc(user, {**values, "description": "autre contenu"}, token, "127.0.0.1")
        assert error.value.status == 409 and counts() == (1, 1, 1)


@pytest.mark.parametrize("same_submission", [False, True])
def test_mysql_concurrent_creations(mysql_app, user, values, same_submission):
    with mysql_app.app_context():
        token1 = new_submission(user)
        token2 = token1 if same_submission else new_submission(user)
    barrier = threading.Barrier(2)
    def run(token):
        with mysql_app.app_context():
            barrier.wait(timeout=5)
            return create_nc(user, values, token, "127.0.0.1")
    with ThreadPoolExecutor(max_workers=2) as pool:
        a, b = [job.result(timeout=30) for job in [pool.submit(run, token1), pool.submit(run, token2)]]
    with mysql_app.app_context():
        if same_submission:
            assert a[0] == b[0] and sorted([a[1], b[1]]) == [False, True]
            assert counts() == (1, 1, 1)
        else:
            assert a[0] != b[0] and counts() == (2, 2, 1)
            with cursor() as cur:
                cur.execute("SELECT COUNT(DISTINCT reference) AS total FROM non_conformites")
                assert cur.fetchone()["total"] == 2


def test_mysql_audit_failure_rolls_back_everything(mysql_app, user, values):
    with mysql_app.app_context():
        # Un trigger de recette, dans cette base neuve seulement.
        with cursor() as cur:
            cur.execute("CREATE TRIGGER refuse_audit BEFORE INSERT ON journal_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Echec audit injecte'")
        with pytest.raises(pymysql.MySQLError):
            create_nc(user, values, new_submission(user), "127.0.0.1")
        assert counts() == (0, 0, 0)


def test_mysql_lost_commit_response_is_recoverable(mysql_app, user, values, monkeypatch):
    import anrt.service as service
    with mysql_app.app_context():
        original = service.connect
        token = new_submission(user)
        def uncertain_connection():
            connection = original()
            real_commit = connection.commit
            def lost_response():
                real_commit()
                raise pymysql.OperationalError(2013, "Reponse perdue apres commit")
            connection.commit = lost_response
            return connection
        monkeypatch.setattr(service, "connect", uncertain_connection)
        with pytest.raises(pymysql.OperationalError):
            create_nc(user, values, token, "127.0.0.1")
        monkeypatch.setattr(service, "connect", original)
        nc_id, replay = create_nc(user, values, token, "127.0.0.1")
        assert nc_id > 0 and replay and counts() == (1, 1, 1)


def test_mysql_reference_999_and_1000(mysql_app, user, values):
    with mysql_app.app_context():
        today = business_today()
        with cursor() as cur:
            cur.execute("INSERT INTO compteurs_reference VALUES ('NC',1,%s,998)", (today.year,))
            cur.execute("SELECT LPAD('1000',3,'0') AS truncated")
            assert cur.fetchone()["truncated"] == "100"
        nc_id, _ = create_nc(user, values, new_submission(user), "127.0.0.1")
        assert "-999-" in detail_nc(user, nc_id)[0]["reference"]
        with pytest.raises(FormError) as error:
            create_nc(user, values, new_submission(user), "127.0.0.1")
        assert error.value.status == 409 and counts() == (1, 1, 1)
        with cursor() as cur:
            cur.execute("SELECT derniere_valeur FROM compteurs_reference")
            assert cur.fetchone()["derniere_valeur"] == 999


@pytest.mark.parametrize("field,value", [("processus_id", "999999"), ("type_nc_id", "999999"), ("nature_service_id", "999999"), ("responsable_matricule", "inconnu")])
def test_mysql_unknown_references_rejected(mysql_app, user, values, field, value):
    with mysql_app.app_context(), pytest.raises(FormError):
        create_nc(user, {**values, field: value}, new_submission(user), "127.0.0.1")
    with mysql_app.app_context():
        assert counts() == (0, 0, 0)


def test_mysql_inactive_and_unpermitted_assignment(mysql_app, user, values):
    with mysql_app.app_context():
        with cursor() as cur:
            cur.execute("UPDATE types_nc SET actif=FALSE WHERE id=1")
        with pytest.raises(FormError):
            create_nc(user, values, new_submission(user), "127.0.0.1")
        with cursor() as cur:
            cur.execute("UPDATE types_nc SET actif=TRUE WHERE id=1")
        with pytest.raises(FormError):
            create_nc(user, {**values, "responsable_matricule": "00018"}, new_submission(user), "127.0.0.1")
        assert counts() == (0, 0, 0)


def test_mysql_scope_filters_pagination_and_inactive_history(mysql_app, user, values):
    with mysql_app.app_context():
        ids = [create_nc(user, values, new_submission(user), "127.0.0.1")[0] for _ in range(21)]
        outsider = {**user, "matricule": "00018"}
        other_id = create_nc(outsider, {**values, "responsable_matricule": "00018"}, new_submission(outsider), "127.0.0.1")[0]
        result = list_nc(user, {}, 1)
        assert result["total"] == 21 and len(result["rows"]) == 20 and result["pages"] == 2
        second = list_nc(user, {}, 2)
        assert len(second["rows"]) == 1
        assert {r["id"] for r in result["rows"]}.isdisjoint({r["id"] for r in second["rows"]})
        assert detail_nc(user, other_id) == (None, [])
        assert list_nc(user, {"responsable_matricule": "00018"}, 1)["total"] == 0
        assert list_nc(user, {"statut_code": "CLOTUREE"}, 1)["total"] == 0
        reference = detail_nc(user, ids[0])[0]["reference"]
        assert list_nc(user, {"reference": reference}, 1)["total"] == 1
        with cursor() as cur:
            cur.execute("UPDATE processus SET actif=FALSE WHERE id=1")
        assert detail_nc(user, ids[0])[0]["id"] == ids[0]
        assert list_nc(user, {"processus_id": "1"}, 1)["total"] == 21

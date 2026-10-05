"""Vérifier le protocole de transaction avec une connexion instrumentée.

Ces tests vérifient l'orchestration et les messages ; seule la recette MySQL
peut établir l'atomicité et les garanties concurrentes du stockage réel.
"""
import json
from unittest.mock import MagicMock

import pymysql
import pytest

from anrt.permissions import DEMO_POLICY
from anrt.service import create_nc, new_submission
from anrt.validation import FormError


@pytest.fixture
def connection(app, monkeypatch, user):
    app.config["PERMISSIONS"] = DEMO_POLICY
    conn = MagicMock()
    cur = conn.cursor.return_value.__enter__.return_value
    cur.nextset.return_value = None
    cur.lastrowid = 41
    monkeypatch.setattr("anrt.service.connect", lambda: conn)
    monkeypatch.setattr("anrt.service.load_user", lambda *args, **kwargs: user)
    return conn, cur


def creation_results(number=1):
    # Acquisition, pas de succès antérieur, trois références, responsable,
    # compteur, référence, puis photographie de la NC réellement insérée.
    return [{"acquired": 1}, None, {"id": 1}, {"id": 1}, {"id": 1},
            {"matricule": "00017"}, {"derniere_valeur": number},
            {"reference": "NC-PO-001-26"}, {"id": 41, "reference": "NC-PO-001-26", "description": "Un constat précis"}]


def test_creation_uses_one_connection_and_audits_actor(app, user, values, connection):
    conn, cur = connection
    cur.fetchone.side_effect = creation_results()
    with app.app_context():
        assert create_nc(user, values, new_submission(user), "127.0.0.1") == (41, False)
    conn.begin.assert_called_once()
    conn.commit.assert_called_once()
    conn.rollback.assert_not_called()
    conn.close.assert_called_once()
    statements = [call.args for call in cur.execute.call_args_list]
    assert sum("CALL generer_reference" in s[0] for s in statements) == 1
    insert = next(s for s in statements if "INSERT INTO non_conformites" in s[0])
    assert insert[1][4] == "00017" and insert[1][7] == "00017"
    audit = next(s for s in statements if "INSERT INTO journal_audit" in s[0])
    assert audit[1][0:2] == ("00017", "41")
    assert json.loads(audit[1][2])["reference"] == "NC-PO-001-26"
    assert "RELEASE_LOCK" in statements[-1][0]


def test_audit_failure_does_not_commit_and_releases_lock(app, user, values, connection):
    conn, cur = connection
    cur.fetchone.side_effect = creation_results()
    def fail_audit(sql, *args):
        if "INSERT INTO journal_audit" in sql:
            raise pymysql.OperationalError(1644, "injected")
    cur.execute.side_effect = fail_audit
    with app.app_context(), pytest.raises(pymysql.OperationalError):
        create_nc(user, values, new_submission(user), "127.0.0.1")
    conn.commit.assert_not_called()
    conn.rollback.assert_called_once()
    conn.close.assert_called_once()
    assert "RELEASE_LOCK" in cur.execute.call_args.args[0]


def test_counter_overflow_is_refused_before_insertion(app, user, values, connection):
    conn, cur = connection
    cur.fetchone.side_effect = creation_results(number=1000)
    with app.app_context(), pytest.raises(FormError) as error:
        create_nc(user, values, new_submission(user), "127.0.0.1")
    assert error.value.status == 409
    conn.commit.assert_not_called()
    conn.rollback.assert_called_once()
    assert not any("INSERT INTO non_conformites" in call.args[0] for call in cur.execute.call_args_list)


def test_validation_rejection_does_not_allocate_reference(app, user, values, connection):
    conn, cur = connection
    cur.fetchone.side_effect = [{"acquired": 1}, None]
    with app.app_context(), pytest.raises(FormError):
        create_nc(user, {**values, "description": " "}, new_submission(user), "127.0.0.1")
    assert not any("CALL generer_reference" in call.args[0] for call in cur.execute.call_args_list)
    conn.commit.assert_not_called()
    conn.rollback.assert_called_once()


def test_replay_recovers_same_id_without_second_counter_allocation(app, user, values, connection):
    conn, cur = connection
    cur.fetchone.side_effect = creation_results()
    with app.app_context():
        token = new_submission(user)
        nc_id, _ = create_nc(user, values, token, "127.0.0.1")
        calls = [call.args for call in cur.execute.call_args_list]
        snapshot = json.loads(next(c for c in calls if "INSERT INTO journal_audit" in c[0])[1][2])
        conn.reset_mock()
        cur.reset_mock()
        cur.fetchone.side_effect = [{"acquired": 1}, {"entite_id": str(nc_id), "nouvelles_valeurs": json.dumps(snapshot)}]
        assert create_nc(user, values, token, "127.0.0.1") == (nc_id, True)
    conn.commit.assert_not_called()
    assert not any("CALL generer_reference" in call.args[0] for call in cur.execute.call_args_list)


def test_transaction_rechecks_account_before_allocation(app, user, values, connection, monkeypatch):
    conn, cur = connection
    cur.fetchone.side_effect = [{"acquired": 1}]
    monkeypatch.setattr("anrt.service.load_user", lambda *_, **__: None)
    with app.app_context(), pytest.raises(FormError) as error:
        create_nc(user, values, new_submission(user), "127.0.0.1")
    assert error.value.status == 403
    conn.rollback.assert_called_once()
    conn.commit.assert_not_called()

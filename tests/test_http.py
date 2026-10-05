import re
from datetime import date, datetime

import pymysql
import pytest
from werkzeug.datastructures import MultiDict

from anrt.permissions import DEMO_POLICY
from anrt.validation import FormError


def csrf(client):
    page = client.get("/connexion")
    return re.search(r'name="csrf_token" value="([^"]+)"', page.text).group(1)


def signed_in(client, monkeypatch, user):
    monkeypatch.setattr("anrt.auth.load_user", lambda _: dict(user))
    with client.session_transaction() as session:
        session["matricule"] = user["matricule"]
        session["csrf"] = "test-csrf"


def options():
    return {"processus": [{"id": 1, "code": "SI", "nom": "Système d'information"}],
            "types_nc": [{"id": 1, "libelle": "Autre"}],
            "natures_service": [{"id": 1, "libelle": "Autre"}],
            "responsables": [{"matricule": "00017", "nom_prenom": "Recette"}],
            "statuts": [{"code": "OUVERTE", "libelle": "Ouverte"}]}


def test_login_page_and_security_headers(client):
    response = client.get("/connexion")
    assert response.status_code == 200
    assert "Matricule" in response.text
    assert response.headers["Cache-Control"] == "no-store"
    assert "frame-ancestors 'none'" in response.headers["Content-Security-Policy"]
    assert "HttpOnly" in response.headers["Set-Cookie"]


def test_csrf_required_even_for_login_and_logout(client):
    assert client.post("/connexion", data={"matricule": "00017", "password": "secret"}).status_code == 400
    assert client.post("/deconnexion").status_code == 400
    assert client.post("/connexion", data={"csrf_token": "é"}).status_code == 400


def test_login_rotates_session_and_logout_revokes_replayed_cookie(client, app, monkeypatch, user):
    monkeypatch.setattr("anrt.routes.authenticate", lambda m, p: user)
    token = csrf(client)
    previous = client.get_cookie("anrt_session").value
    response = client.post("/connexion", data={"csrf_token": token, "matricule": "00017", "password": "secret"})
    assert response.status_code == 303
    active = client.get_cookie("anrt_session").value
    assert active != previous
    with client.session_transaction() as session:
        assert session["matricule"] == "00017"
        session["csrf"] = "logout-token"
    assert client.post("/deconnexion", data={"csrf_token": "logout-token"}).status_code == 303
    replay = app.test_client()
    replay.set_cookie("anrt_session", active)
    assert replay.get("/non-conformites").status_code == 302


def test_login_failure_and_rate_limit(client, monkeypatch):
    monkeypatch.setattr("anrt.routes.authenticate", lambda m, p: None)
    token = csrf(client)
    for _ in range(8):
        assert client.post("/connexion", data={"csrf_token": token, "matricule": "00017", "password": "bad"}).status_code == 401
    assert client.post("/connexion", data={"csrf_token": token, "matricule": "00017", "password": "bad"}).status_code == 429


def test_direct_create_denied_to_consultation(client, app, monkeypatch, user):
    app.config["PERMISSIONS"] = DEMO_POLICY
    user["profiles"] = ["CONSULTATION"]
    signed_in(client, monkeypatch, user)
    assert client.get("/non-conformites/nouvelle").status_code == 403
    assert client.post("/non-conformites/nouvelle", data={"csrf_token": "test-csrf"}).status_code == 403


def test_account_rechecked_on_each_operation(client, monkeypatch, user):
    signed_in(client, monkeypatch, user)
    monkeypatch.setattr("anrt.auth.load_user", lambda _: None)
    assert client.get("/non-conformites/nouvelle").status_code == 302
    with client.session_transaction() as session:
        assert "matricule" not in session


@pytest.mark.parametrize("status", [422, 503])
def test_values_kept_after_validation_or_database_error(client, app, monkeypatch, user, values, status):
    app.config["PERMISSIONS"] = DEMO_POLICY
    signed_in(client, monkeypatch, user)
    monkeypatch.setattr("anrt.routes.choices", lambda *_, **__: options())
    def fail(*args):
        if status == 503:
            raise pymysql.OperationalError(2013, "internal secret should never be displayed")
        raise FormError({"description": "Erreur de recette"})
    monkeypatch.setattr("anrt.routes.create_nc", fail)
    values["description"] = '<script>alert("x")</script> & constat'
    response = client.post("/non-conformites/nouvelle", data={**values, "csrf_token": "test-csrf", "submission_token": "keep-token"})
    assert response.status_code == status
    assert "&lt;script&gt;" in response.text
    assert '<script>alert("x")' not in response.text
    assert 'value="keep-token"' in response.text
    assert "internal secret" not in response.text
    assert "Un traitement réel" in response.text


def test_values_kept_when_database_and_choices_are_unavailable(client, app, monkeypatch, user, values):
    app.config["PERMISSIONS"] = DEMO_POLICY
    signed_in(client, monkeypatch, user)
    def unavailable(*args, **kwargs):
        raise pymysql.OperationalError(2003, "unavailable")
    monkeypatch.setattr("anrt.routes.create_nc", unavailable)
    monkeypatch.setattr("anrt.routes.choices", unavailable)
    response = client.post("/non-conformites/nouvelle", data={**values, "csrf_token": "test-csrf", "submission_token": "retry-key"})
    assert response.status_code == 503
    assert "Un constat précis" in response.text and "Un traitement réel" in response.text
    assert 'value="retry-key"' in response.text
    assert 'value="00017" selected' in response.text


def test_pagination_links_keep_filters(client, app, monkeypatch, user):
    app.config["PERMISSIONS"] = DEMO_POLICY
    signed_in(client, monkeypatch, user)
    monkeypatch.setattr("anrt.routes.choices", lambda *_, **__: options())
    monkeypatch.setattr("anrt.routes.list_nc", lambda *_: {"rows": [], "total": 21, "page": 1, "pages": 2})
    response = client.get("/non-conformites?reference=NC-SI&processus_id=1&statut_code=OUVERTE")
    assert response.status_code == 200
    assert "reference=NC-SI&amp;processus_id=1&amp;statut_code=OUVERTE&amp;page=2" in response.text



def test_missing_initial_rule_confirmation_blocks_creation(client, app, monkeypatch, user):
    app.config.update(PERMISSIONS=DEMO_POLICY, NC_INITIAL_RULES_CONFIRMED=False)
    signed_in(client, monkeypatch, user)
    assert client.get("/non-conformites/nouvelle").status_code == 403


def test_protected_and_duplicate_fields_rejected(client, app, monkeypatch, user, values):
    app.config["PERMISSIONS"] = DEMO_POLICY
    signed_in(client, monkeypatch, user)
    monkeypatch.setattr("anrt.routes.choices", lambda *_, **__: options())
    def must_not_create(*args):
        pytest.fail("Les valeurs protégées ne doivent pas atteindre la création")
    monkeypatch.setattr("anrt.routes.create_nc", must_not_create)
    payload = {**values, "csrf_token": "test-csrf", "submission_token": "token", "statut_code": "CLOTUREE"}
    assert client.post("/non-conformites/nouvelle", data=payload).status_code == 400
    del payload["statut_code"]
    repeated = MultiDict(payload)
    repeated.add("description", "autre")
    assert client.post("/non-conformites/nouvelle", data=repeated).status_code == 400


def test_page_and_filter_rendering(client, app, monkeypatch, user):
    app.config["PERMISSIONS"] = DEMO_POLICY
    signed_in(client, monkeypatch, user)
    monkeypatch.setattr("anrt.routes.choices", lambda *_, **__: options())
    monkeypatch.setattr("anrt.routes.list_nc", lambda *_: {"rows": [], "total": 0, "page": 1, "pages": 1})
    response = client.get("/non-conformites?reference=NC-SI&processus_id=1")
    assert response.status_code == 200
    assert "Aucune non-conformité" in response.text
    assert 'value="NC-SI"' in response.text
    assert client.get("/non-conformites?page=-1").status_code == 400


def test_detail_and_audit_render_without_sensitive_hr_data(client, app, monkeypatch, user):
    signed_in(client, monkeypatch, user)
    nc = dict(id=1, reference="NC-SI-001-26", statut_code="OUVERTE", statut="Ouverte",
              date_creation=date(2026, 10, 5), date_echeance=None, updated_at=datetime(2026, 10, 5),
              declarant="Personne", enregistree_par_matricule="00017", responsable="Responsable",
              responsable_matricule="00017", processus_code="SI", processus="Informatique",
              type_nc="Autre", nature_service="Autre", description="Constat", traitement="Traitement")
    audit = [dict(operation="CREATION", acteur="Personne", acteur_matricule="00017", created_at=datetime(2026, 10, 5))]
    monkeypatch.setattr("anrt.routes.detail_nc", lambda *_: (nc, audit))
    response = client.get("/non-conformites/1")
    assert response.status_code == 200
    assert "Historique" in response.text and "NC-SI-001-26" in response.text
    monkeypatch.setattr("anrt.routes.detail_nc", lambda *_: (None, []))
    assert client.get("/non-conformites/2").status_code == 404


@pytest.mark.parametrize("path", ["/non-conformites", "/non-conformites/nouvelle", "/non-conformites/1"])
def test_direct_access_without_authentication_redirects_to_login(client, path):
    response = client.get(path)
    assert response.status_code == 302
    assert response.headers["Location"].endswith("/connexion")


def test_full_http_journey_with_database_doubles(client, app, monkeypatch, user, values):
    """Navigation réelle dans Flask ; les appels métier MySQL sont remplacés."""
    app.config["PERMISSIONS"] = DEMO_POLICY
    monkeypatch.setattr("anrt.routes.authenticate", lambda m, p: user)
    monkeypatch.setattr("anrt.auth.load_user", lambda _: dict(user))
    monkeypatch.setattr("anrt.routes.choices", lambda *_, **__: options())
    monkeypatch.setattr("anrt.routes.list_nc", lambda *_: {"rows": [], "total": 0, "page": 1, "pages": 1})
    token = csrf(client)
    response = client.post("/connexion", data={"csrf_token": token, "matricule": "00017", "password": "secret"}, follow_redirects=True)
    assert response.status_code == 200 and "Nouvelle non-conformité" in response.text

    page = client.get("/non-conformites/nouvelle")
    token = re.search(r'name="csrf_token" value="([^"]+)"', page.text).group(1)
    submission = re.search(r'name="submission_token" value="([^"]+)"', page.text).group(1)
    calls = []
    def record_creation(actor, data, key, address):
        calls.append((actor["matricule"], dict(data), key))
        if not data["description"].strip():
            raise FormError({"description": "Ce champ est obligatoire."})
        return 41, False
    monkeypatch.setattr("anrt.routes.create_nc", record_creation)
    nc = dict(id=41, reference="NC-PO-001-26", statut_code="OUVERTE", statut="Ouverte",
              date_creation=date(2026, 10, 5), date_echeance=None, updated_at=datetime(2026, 10, 5),
              declarant=user["nom_prenom"], enregistree_par_matricule="00017",
              responsable="Recette", responsable_matricule="00017", processus_code="PO",
              processus="Politique", type_nc="Autre", nature_service="Autre",
              description=values["description"], traitement=values["traitement"])
    monkeypatch.setattr("anrt.routes.detail_nc", lambda *_: (nc, []))
    payload = {**values, "csrf_token": token, "submission_token": submission}
    invalid = client.post("/non-conformites/nouvelle", data={**payload, "description": " "})
    assert invalid.status_code == 422 and "Ce champ est obligatoire." in invalid.text
    assert submission in invalid.text and values["traitement"] in invalid.text
    saved = client.post("/non-conformites/nouvelle", data=payload)
    assert saved.status_code == 303 and saved.headers["Location"].endswith("/non-conformites/41")
    detail = client.get(saved.headers["Location"])
    assert "Votre non-conformité a été enregistrée." in detail.text
    assert nc["reference"] in detail.text and values["description"] in detail.text
    assert calls[-1] == ("00017", values, submission)
    logout_token = re.search(r'name="csrf_token" value="([^"]+)"', detail.text).group(1)
    assert client.post("/deconnexion", data={"csrf_token": logout_token}).status_code == 303
    assert client.get("/non-conformites/41").status_code == 302


@pytest.mark.parametrize("profile", ["ADMINISTRATEUR", "PILOTE_PROCESSUS", "RESPONSABLE", "CONSULTATION"])
def test_every_profile_is_denied_when_permissions_are_unconfirmed(client, monkeypatch, user, profile):
    user["profiles"] = [profile]
    signed_in(client, monkeypatch, user)
    assert client.get("/non-conformites").status_code == 403
    assert client.get("/non-conformites/nouvelle").status_code == 403
    assert client.post("/non-conformites/nouvelle", data={"csrf_token": "test-csrf"}).status_code == 403

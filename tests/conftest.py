import pytest

from anrt import create_app


@pytest.fixture
def app(tmp_path):
    return create_app({
        "TESTING": True, "SECRET_KEY": "test-only-key-never-use-in-production-12345",
        "SESSION_STORE": tmp_path / "sessions.sqlite3", "SESSION_COOKIE_SECURE": False,
        "DEMO_MODE": False, "PERMISSIONS": {}, "LOGIN_IDENTIFIER": "matricule",
        "APP_TIMEZONE": "UTC", "NC_INITIAL_RULES_CONFIRMED": True,
    })


@pytest.fixture
def client(app):
    return app.test_client()


@pytest.fixture
def user():
    return {"matricule": "00017", "nom_prenom": "Personne de recette", "profiles": ["RESPONSABLE"]}


@pytest.fixture
def values():
    return {"processus_id": "1", "type_nc_id": "1", "nature_service_id": "1",
            "description": "Un constat précis", "traitement": "Un traitement réel",
            "responsable_matricule": "00017", "date_echeance": ""}

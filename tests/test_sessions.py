import sqlite3

import pytest
from werkzeug.wrappers import Request


def test_sqlite_connection_commits_or_rolls_back_and_always_closes(app):
    store = app.session_interface
    with store.connection() as connection:
        connection.execute("CREATE TABLE closure_check (value TEXT)")
        connection.execute("INSERT INTO closure_check VALUES ('committed')")
    with pytest.raises(sqlite3.ProgrammingError):
        connection.execute("SELECT 1")

    with pytest.raises(RuntimeError), store.connection() as failed_connection:
        failed_connection.execute("INSERT INTO closure_check VALUES ('rolled back')")
        raise RuntimeError("Simuler une erreur pendant l'écriture")
    with pytest.raises(sqlite3.ProgrammingError):
        failed_connection.execute("SELECT 1")
    with store.connection() as connection:
        assert connection.execute("SELECT value FROM closure_check").fetchall() == [("committed",)]


def test_in_flight_request_cannot_restore_revoked_session(app, client):
    with client.session_transaction() as session:
        session["matricule"] = "00017"
    sid = client.get_cookie("anrt_session").value
    request = Request.from_values(headers={"Cookie": f"anrt_session={sid}"})
    store = app.session_interface
    active = store.open_session(app, request)
    in_flight = store.open_session(app, request)
    store.revoke(active)
    in_flight["late_change"] = True
    response = app.response_class()
    store.save_session(app, in_flight, response)
    assert "Set-Cookie" not in response.headers
    assert "matricule" not in store.open_session(app, request)


def test_expired_session_cannot_access_protected_page(app, client):
    with client.session_transaction() as session:
        session["matricule"] = "00017"
    with app.session_interface.connection() as connection:
        connection.execute("UPDATE sessions SET expires=0")
    response = client.get("/non-conformites")
    assert response.status_code == 302
    assert response.headers["Location"].endswith("/connexion")

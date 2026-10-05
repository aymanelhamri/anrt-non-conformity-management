import functools
import secrets

import bcrypt
from argon2 import PasswordHasher
from argon2.exceptions import Argon2Error
from flask import abort, current_app, g, redirect, request, session, url_for

from .db import cursor

HASHER = PasswordHasher()
DUMMY_HASH = HASHER.hash(secrets.token_urlsafe(32))


def verify_password(encoded, password):
    try:
        if encoded.startswith("$argon2id$"):
            return HASHER.verify(encoded, password)
        if encoded.startswith(("$2a$", "$2b$", "$2y$")):
            raw = password.encode("utf-8")
            return len(raw) <= 72 and bcrypt.checkpw(raw, encoded.encode("ascii"))
    except (Argon2Error, ValueError, UnicodeError):
        pass
    return False


def load_user(matricule, cur=None, lock=False):
    if cur is None:
        with cursor() as cur:
            return load_user(matricule, cur)
    cur.execute("""SELECT a.matricule, p.nom_prenom FROM acces a
        JOIN pers p ON p.matricule=a.matricule
        WHERE a.matricule=%s AND a.Date_Deb<=CURRENT_TIMESTAMP
        AND (a.Date_Fin IS NULL OR a.Date_Fin>CURRENT_TIMESTAMP)""" + (" FOR SHARE" if lock else ""), (matricule,))
    user = cur.fetchone()
    if user:
        cur.execute("SELECT profil_code FROM acces_profil WHERE matricule=%s" + (" FOR SHARE" if lock else ""), (matricule,))
        user["profiles"] = [r["profil_code"] for r in cur.fetchall()]
    return user


def authenticate(matricule, password):
    with cursor() as cur:
        cur.execute("SELECT passe FROM acces WHERE matricule=%s", (matricule,))
        row = cur.fetchone()
        valid = verify_password(row["passe"] if row else DUMMY_HASH, password)
        return load_user(matricule, cur) if valid and row else None


def csrf_token():
    if "csrf" not in session:
        session["csrf"] = secrets.token_urlsafe(32)
    return session["csrf"]


def check_csrf():
    if request.method == "POST":
        token = request.form.get("csrf_token", "")
        if not session.get("csrf") or not secrets.compare_digest(token.encode("utf-8"), session["csrf"].encode("utf-8")):
            abort(400, "Le formulaire a expiré. Rechargez la page avant de réessayer.")


def login_required(view):
    @functools.wraps(view)
    def wrapper(*args, **kwargs):
        matricule = session.get("matricule")
        g.user = load_user(matricule) if matricule else None
        if not g.user:
            current_app.session_interface.revoke(session)
            return redirect(url_for("web.login"))
        return view(*args, **kwargs)
    return wrapper

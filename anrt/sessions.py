"""Stockage technique local, sans ajout/modification du schéma métier MySQL.

Sessions opaques, révocables à la déconnexion, partagées entre workers locaux.
SQLite doit être sur un disque local et privé, jamais dans le dossier static.
"""
import hashlib
import json
import secrets
import sqlite3
import time
from contextlib import contextmanager

from flask.sessions import SessionInterface, SessionMixin
from werkzeug.datastructures import CallbackDict


class ServerSession(CallbackDict, SessionMixin):
    def __init__(self, data=None, sid=None, existing=False):
        super().__init__(data, lambda obj: setattr(obj, "modified", True))
        self.sid = sid or secrets.token_urlsafe(32)
        self.existing = existing
        self.modified = False


class SQLiteSessions(SessionInterface):
    def __init__(self, path):
        self.path = str(path)
        with self.connection() as db:
            db.execute("PRAGMA journal_mode=WAL")
            db.execute("CREATE TABLE IF NOT EXISTS sessions (sid TEXT PRIMARY KEY, data TEXT NOT NULL, expires REAL NOT NULL)")
            db.execute("CREATE TABLE IF NOT EXISTS attempts (key TEXT PRIMARY KEY, count INTEGER NOT NULL, until REAL NOT NULL)")

    @contextmanager
    def connection(self):
        with sqlite3.connect(self.path, timeout=10) as db:
            yield db

    def open_session(self, app, request):
        sid = request.cookies.get(self.get_cookie_name(app), "")
        if len(sid) == 43:
            with self.connection() as db:
                row = db.execute("SELECT data FROM sessions WHERE sid=? AND expires>?", (sid, time.time())).fetchone()
            if row:
                return ServerSession(json.loads(row[0]), sid, existing=True)
        return ServerSession()

    def revoke(self, session):
        with self.connection() as db:
            db.execute("DELETE FROM sessions WHERE sid=?", (session.sid,))
        session.clear()
        session.sid = secrets.token_urlsafe(32)
        session.existing = False

    def save_session(self, app, session, response):
        response.vary.add("Cookie")
        name = self.get_cookie_name(app)
        with self.connection() as db:
            db.execute("DELETE FROM sessions WHERE expires<=?", (time.time(),))
            if not session:
                db.execute("DELETE FROM sessions WHERE sid=?", (session.sid,))
                response.delete_cookie(name, path="/", secure=app.config["SESSION_COOKIE_SECURE"], httponly=True, samesite="Lax")
                return
            data = json.dumps(dict(session), ensure_ascii=False)
            expires = time.time() + app.config["SESSION_SECONDS"]
            # UPDATE only: a parallel request cannot resurrect a revoked session.
            if session.existing:
                result = db.execute("UPDATE sessions SET data=?, expires=? WHERE sid=?", (data, expires, session.sid))
                if result.rowcount == 0:
                    return
            else:
                db.execute("INSERT INTO sessions VALUES (?, ?, ?)", (session.sid, data, expires))
        response.set_cookie(name, session.sid, max_age=app.config["SESSION_SECONDS"], secure=app.config["SESSION_COOKIE_SECURE"], httponly=True, samesite="Lax", path="/")

    def allow_login(self, identity, address):
        # Deux limites : IP (30/10 min) et couple IP/identifiant (8/10 min).
        now = time.time()
        keys = [(hashlib.sha256(address.encode()).hexdigest(), 30),
                (hashlib.sha256((address + "\0" + identity).encode()).hexdigest(), 8)]
        with self.connection() as db:
            db.execute("BEGIN IMMEDIATE")
            db.execute("DELETE FROM attempts WHERE until<=?", (now,))
            for key, limit in keys:
                row = db.execute("SELECT count FROM attempts WHERE key=?", (key,)).fetchone()
                if row and row[0] >= limit:
                    return False
            for key, _ in keys:
                db.execute("INSERT INTO attempts VALUES (?, 1, ?) ON CONFLICT(key) DO UPDATE SET count=count+1", (key, now + 600))
        return True

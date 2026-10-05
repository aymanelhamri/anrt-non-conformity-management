from pathlib import Path
from zoneinfo import ZoneInfo

from dotenv import load_dotenv
from flask import Flask, render_template
import pymysql

from .auth import check_csrf, csrf_token
from .config import environment
from .permissions import DEMO_POLICY, validate_policy
from .sessions import SQLiteSessions


def create_app(config=None):
    load_dotenv()
    app = Flask(__name__, instance_relative_config=True)
    app.config.update(environment())
    if config:
        app.config.update(config)
    if not app.config["SECRET_KEY"] or len(app.config["SECRET_KEY"]) < 32:
        raise ValueError("SECRET_KEY doit contenir au moins 32 caractères aléatoires, hors du dépôt")
    ZoneInfo(app.config["APP_TIMEZONE"])
    if app.config["DEMO_MODE"]:
        app.config["PERMISSIONS"] = DEMO_POLICY
        app.config["LOGIN_IDENTIFIER"] = "matricule"
    if app.config["LOGIN_IDENTIFIER"] not in ("", "matricule"):
        raise ValueError("Seule la convention de connexion matricule est implémentée")
    validate_policy(app.config["PERMISSIONS"])
    Path(app.instance_path).mkdir(parents=True, exist_ok=True)
    app.session_interface = SQLiteSessions(app.config.get("SESSION_STORE", Path(app.instance_path) / "sessions.sqlite3"))
    app.before_request(check_csrf)
    app.jinja_env.globals["csrf_token"] = csrf_token
    from .routes import web
    from .cli import register_cli
    app.register_blueprint(web)
    register_cli(app)

    @app.after_request
    def security_headers(response):
        response.headers["Cache-Control"] = "no-store"
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["Referrer-Policy"] = "same-origin"
        response.headers["Content-Security-Policy"] = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'"
        if app.config["SESSION_COOKIE_SECURE"]:
            response.headers["Strict-Transport-Security"] = "max-age=31536000"
        return response

    @app.errorhandler(pymysql.MySQLError)
    def database_error(error):
        # Ne pas logger l'exception SQL : elle peut contenir des valeurs métier.
        app.logger.error("MySQL indisponible (%s)", type(error).__name__)
        return render_template("error.html", message="Le service de données est indisponible. Réessayez dans quelques instants."), 503

    for status in (400, 403, 404, 413, 429):
        def handler(error):
            return render_template("error.html", message=error.description), error.code
        app.register_error_handler(status, handler)
    return app

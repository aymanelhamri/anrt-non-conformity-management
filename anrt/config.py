import json
import os
from pathlib import Path


def env_bool(name, default=False):
    value = os.getenv(name)
    if value is None:
        return default
    if value.lower() not in {"true", "false"}:
        raise ValueError(f"{name} doit valoir true ou false")
    return value.lower() == "true"


def environment():
    path = os.getenv("PERMISSIONS_FILE")
    return {
        "SECRET_KEY": os.getenv("SECRET_KEY"),
        "MYSQL_HOST": os.getenv("MYSQL_HOST", "127.0.0.1"),
        "MYSQL_PORT": int(os.getenv("MYSQL_PORT", "3306")),
        "MYSQL_DATABASE": os.getenv("MYSQL_DATABASE", "anrt_qualite"),
        "MYSQL_USER": os.getenv("MYSQL_USER", "anrt_app"),
        "MYSQL_PASSWORD": os.getenv("MYSQL_PASSWORD", ""),
        "MYSQL_SSL_CA": os.getenv("MYSQL_SSL_CA") or None,
        "APP_TIMEZONE": os.getenv("APP_TIMEZONE", "Africa/Casablanca"),
        "SESSION_COOKIE_SECURE": env_bool("SESSION_COOKIE_SECURE", True),
        "SESSION_COOKIE_HTTPONLY": True,
        "SESSION_COOKIE_SAMESITE": "Lax",
        "SESSION_COOKIE_NAME": "anrt_session",
        "SESSION_SECONDS": 1800,
        "MAX_CONTENT_LENGTH": 600_000,
        "DEMO_MODE": env_bool("DEMO_MODE"),
        "LOGIN_IDENTIFIER": os.getenv("LOGIN_IDENTIFIER", ""),
        "NC_INITIAL_RULES_CONFIRMED": env_bool("NC_INITIAL_RULES_CONFIRMED"),
        "NC_DEADLINE_REQUIRED": env_bool("NC_DEADLINE_REQUIRED"),
        "PERMISSIONS": json.loads(Path(path).read_text(encoding="utf-8")) if path else {},
    }

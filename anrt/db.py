from contextlib import contextmanager

import pymysql
from flask import current_app


def connect():
    cfg = current_app.config
    options = dict(
        host=cfg["MYSQL_HOST"], port=cfg["MYSQL_PORT"], user=cfg["MYSQL_USER"],
        password=cfg["MYSQL_PASSWORD"], database=cfg["MYSQL_DATABASE"],
        charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor,
        autocommit=True, connect_timeout=5, read_timeout=20, write_timeout=20,
        init_command="SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
    )
    if cfg["MYSQL_SSL_CA"]:
        options["ssl"] = {"ca": cfg["MYSQL_SSL_CA"], "check_hostname": True}
        options["ssl_verify_cert"] = True
        options["ssl_verify_identity"] = True
    return pymysql.connect(**options)


@contextmanager
def cursor():
    connection = connect()
    try:
        with connection.cursor() as cur:
            yield cur
    finally:
        connection.close()

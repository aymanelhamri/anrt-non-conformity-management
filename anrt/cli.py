from getpass import getpass

import click

from .auth import HASHER
from .db import cursor


def register_cli(app):
    @app.cli.command("hash-password")
    def hash_password():
        """Produire un hash Argon2id sans exposer le mot de passe en argument."""
        password = getpass("Mot de passe : ")
        if password != getpass("Confirmation : ") or not password:
            raise click.ClickException("Confirmation incorrecte ou mot de passe vide")
        if len(password.encode("utf-8")) > 1024:
            raise click.ClickException("Mot de passe trop long")
        click.echo(HASHER.hash(password))

    @app.cli.command("check-db")
    def check_db():
        """Vérifier la connexion sans écrire dans la base métier."""
        with cursor() as cur:
            cur.execute("SELECT VERSION() AS version, @@session.time_zone AS timezone")
            result = cur.fetchone()
            cur.execute("SELECT COUNT(*) AS total FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_name='generer_reference'")
            if cur.fetchone()["total"] != 1:
                raise click.ClickException("La procédure generer_reference manque")
            cur.execute("SELECT COUNT(*) AS total FROM statuts WHERE code='OUVERTE'")
            if cur.fetchone()["total"] != 1:
                raise click.ClickException("Le référentiel OUVERTE manque")
        click.echo(f"MySQL {result['version']} ; session {result['timezone']} ; procédure et statut présents")

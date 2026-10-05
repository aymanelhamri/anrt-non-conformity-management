from datetime import timezone
from zoneinfo import ZoneInfo

import pymysql
from flask import Blueprint, abort, current_app, flash, g, redirect, render_template, request, session, url_for

from .auth import authenticate, login_required
from .permissions import can_create, scope_sql
from .repository import choices, detail_nc, list_nc
from .service import business_today, create_nc, new_submission
from .validation import FIELDS, FormError, uint

web = Blueprint("web", __name__)


@web.app_template_filter("datetime_local")
def datetime_local(value):
    if not value:
        return "—"
    return value.replace(tzinfo=timezone.utc).astimezone(ZoneInfo(current_app.config["APP_TIMEZONE"])).strftime("%d/%m/%Y %H:%M")


@web.route("/")
def index():
    return redirect(url_for("web.nc_list"))


@web.route("/connexion", methods=["GET", "POST"])
def login():
    error, status = None, 200
    identifier = request.form.get("matricule", "")
    if request.method == "POST":
        if current_app.config["LOGIN_IDENTIFIER"] != "matricule":
            error, status = "La convention de connexion doit être configurée par l'administrateur.", 503
        elif not current_app.session_interface.allow_login(identifier, request.remote_addr or "unknown"):
            error, status = "Trop de tentatives. Réessayez dans dix minutes.", 429
        else:
            password = request.form.get("password", "")
            user = authenticate(identifier, password) if identifier and len(identifier) <= 60 and len(password.encode("utf-8")) <= 1024 else None
            if user:
                current_app.session_interface.revoke(session)
                session["matricule"] = user["matricule"]
                return redirect(url_for("web.nc_list"), code=303)
            error, status = "Identifiants incorrects ou compte hors période de validité.", 401
    return render_template("login.html", error=error, identifier=identifier), status


@web.route("/deconnexion", methods=["POST"])
def logout():
    current_app.session_interface.revoke(session)
    return redirect(url_for("web.login"), code=303)


@web.route("/non-conformites")
@login_required
def nc_list():
    where, _ = scope_sql(g.user)
    if where == "0=1":
        abort(403, "Aucun droit de consultation n'est configuré pour votre compte.")
    requested_page = uint(request.args.get("page", "1"))
    if requested_page is None:
        abort(400, "Numéro de page invalide.")
    filters = {f: request.args.get(f, "") for f in ("reference", "processus_id", "statut_code", "responsable_matricule")}
    try:
        result = list_nc(g.user, filters, requested_page)
    except FormError as error:
        abort(error.status, " ".join(error.errors.values()))
    def page_url(page):
        return url_for("web.nc_list", **{k: v for k, v in filters.items() if v}, page=page)
    return render_template("list.html", **result, filters=filters, options=choices(g.user, include_inactive=True), may_create=can_create(g.user), page_url=page_url)


@web.route("/non-conformites/nouvelle", methods=["GET", "POST"])
@login_required
def nc_new():
    if not can_create(g.user):
        abort(403, "Votre compte ne peut pas créer de non-conformité.")
    if not (current_app.config["DEMO_MODE"] or current_app.config["NC_INITIAL_RULES_CONFIRMED"]):
        abort(403, "La création attend la confirmation des règles de saisie initiale.")
    values = {f: request.form.get(f, "") for f in FIELDS}
    errors, status = {}, 200
    token = request.form.get("submission_token", "") if request.method == "POST" else new_submission(g.user)
    if request.method == "POST":
        try:
            if set(request.form) - FIELDS - {"csrf_token", "submission_token"} or any(len(request.form.getlist(k)) != 1 for k in request.form):
                raise FormError({"form": "Le formulaire contient des champs non autorisés ou répétés."}, 400)
            nc_id, replayed = create_nc(g.user, values, token, request.remote_addr)
            flash("Enregistrement retrouvé : votre non-conformité avait déjà été créée." if replayed else "Votre non-conformité a été enregistrée.", "success")
            return redirect(url_for("web.nc_detail", nc_id=nc_id), code=303)
        except FormError as error:
            errors, status = error.errors, error.status
        except pymysql.MySQLError as error:
            current_app.logger.error("Création MySQL non confirmée (%s)", type(error).__name__)
            errors, status = {"form": "L'enregistrement n'a pas pu être confirmé. Conservez ce formulaire et réessayez avec les mêmes données pour retrouver le résultat sans doublon."}, 503
    try:
        options = choices(g.user)
    except pymysql.MySQLError:
        options = {"processus": [], "types_nc": [], "natures_service": [], "responsables": [], "statuts": []}
        errors.setdefault("form", "Les listes de choix sont indisponibles. Votre saisie est conservée ; réessayez avec ce formulaire.")
        status = 503
    return render_template("new.html", values=values, errors=errors, submission_token=token,
                           options=options, today=business_today(), deadline_required=current_app.config["NC_DEADLINE_REQUIRED"]), status


@web.route("/non-conformites/<int:nc_id>")
@login_required
def nc_detail(nc_id):
    if not 1 <= nc_id <= 4_294_967_295:
        abort(404, "Dossier introuvable ou non autorisé.")
    nc, audit = detail_nc(g.user, nc_id)
    if nc is None:
        abort(404, "Dossier introuvable ou non autorisé.")
    return render_template("detail.html", nc=nc, audit=audit)

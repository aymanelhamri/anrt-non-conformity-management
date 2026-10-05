import re
from datetime import date


class FormError(Exception):
    def __init__(self, errors, status=422):
        self.errors = errors
        self.status = status
        super().__init__("Données invalides")


FIELDS = {"processus_id", "type_nc_id", "nature_service_id", "description", "traitement", "responsable_matricule", "date_echeance"}


def uint(value):
    if not isinstance(value, str) or len(value) > 10 or not re.fullmatch(r"[0-9]+", value):
        return None
    parsed = int(value)
    return parsed if 1 <= parsed <= 4_294_967_295 else None


def validate_form(values, today, deadline_required=False):
    errors, data = {}, {}
    for field in ("processus_id", "type_nc_id", "nature_service_id"):
        data[field] = uint(values.get(field, ""))
        if data[field] is None:
            errors[field] = "Sélectionnez une valeur valide."
    for field in ("description", "traitement"):
        value = values.get(field, "")
        if not value.strip():
            errors[field] = "Ce champ est obligatoire."
        elif len(value.encode("utf-8")) > 65_535:
            errors[field] = "Ce texte dépasse la capacité autorisée (65 535 octets UTF-8)."
        data[field] = value
    matricule = values.get("responsable_matricule", "")
    if not matricule.strip() or len(matricule) > 60:
        errors["responsable_matricule"] = "Sélectionnez un responsable (matricule de 60 caractères maximum)."
    data["responsable_matricule"] = matricule  # Jamais int(), trim() ou troncature.
    deadline = values.get("date_echeance", "")
    data["date_echeance"] = None
    if deadline:
        try:
            if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", deadline):
                raise ValueError
            parsed = date.fromisoformat(deadline)
            if parsed.year < 1000 or parsed < today:
                raise ValueError
            data["date_echeance"] = parsed
        except ValueError:
            errors["date_echeance"] = "Saisissez une date valide égale ou postérieure à la création."
    elif deadline_required:
        errors["date_echeance"] = "L'échéance est obligatoire."
    if errors:
        raise FormError(errors)
    return data

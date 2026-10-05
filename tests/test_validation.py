from datetime import date

import pytest

from anrt.validation import FormError, uint, validate_form

TODAY = date(2026, 10, 5)


@pytest.mark.parametrize("value", ["", "-1", "0", "1.5", "1e2", " 1", "4294967296", "٠١", "9" * 100])
def test_unsigned_id_rejects_invalid(value):
    assert uint(value) is None


def test_unsigned_id_upper_boundary():
    assert uint("4294967295") == 4294967295


def test_preserves_matricule_and_content(values):
    values["description"] = "  Faits à conserver  "
    result = validate_form(values, TODAY)
    assert result["responsable_matricule"] == "00017"
    assert result["description"] == values["description"]
    assert result["date_echeance"] is None


@pytest.mark.parametrize("field", ["description", "traitement", "responsable_matricule"])
def test_whitespace_is_not_required_content(values, field):
    values[field] = " \t\n "
    with pytest.raises(FormError) as error:
        validate_form(values, TODAY)
    assert field in error.value.errors


@pytest.mark.parametrize("field", ["description", "traitement"])
def test_text_byte_boundary(values, field):
    values[field] = "é" * 32767 + "a"
    assert validate_form(values, TODAY)[field] == values[field]
    values[field] += "a"
    with pytest.raises(FormError):
        validate_form(values, TODAY)


def test_four_byte_characters(values):
    values["description"] = "📝" * 16383 + "abc"
    validate_form(values, TODAY)
    values["description"] += "a"
    with pytest.raises(FormError):
        validate_form(values, TODAY)


def test_matricule_length_without_truncation(values):
    values["responsable_matricule"] = "0" * 60
    assert len(validate_form(values, TODAY)["responsable_matricule"]) == 60
    values["responsable_matricule"] += "0"
    with pytest.raises(FormError):
        validate_form(values, TODAY)


@pytest.mark.parametrize("deadline", ["2026-10-04", "2026-02-30", "2026-1-01", "0001-01-01", "not-a-date"])
def test_bad_deadline(values, deadline):
    values["date_echeance"] = deadline
    with pytest.raises(FormError):
        validate_form(values, TODAY)


@pytest.mark.parametrize("deadline", ["2026-10-05", "2026-10-06"])
def test_valid_deadline(values, deadline):
    values["date_echeance"] = deadline
    assert validate_form(values, TODAY)["date_echeance"] == date.fromisoformat(deadline)


def test_required_deadline_is_configurable(values):
    with pytest.raises(FormError):
        validate_form(values, TODAY, deadline_required=True)

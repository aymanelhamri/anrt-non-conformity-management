import bcrypt
import pytest

from anrt.auth import HASHER, verify_password
from anrt.permissions import DEMO_POLICY, can_create, scope_sql, validate_policy
from anrt.service import new_submission, submission_key
from anrt.validation import FormError


def test_argon2id_and_bcrypt():
    assert verify_password(HASHER.hash("mot-de-passe"), "mot-de-passe")
    assert not verify_password(HASHER.hash("mot-de-passe"), "incorrect")
    encoded = bcrypt.hashpw(b"mot-de-passe", bcrypt.gensalt()).decode()
    assert verify_password(encoded, "mot-de-passe")
    assert not verify_password(encoded, "incorrect")
    assert not verify_password(encoded, "a" * 73)
    # Préfixe utilisé par les hashes bcrypt de PHP password_hash().
    assert verify_password(encoded.replace("$2b$", "$2y$", 1), "mot-de-passe")


@pytest.mark.parametrize("encoded", ["plaintext", "$argon2id$invalid", "$2b$invalid", "$argon2i$invalid"])
def test_no_plaintext_or_invalid_hash(encoded):
    assert not verify_password(encoded, encoded)


def test_default_deny_and_consultation_cannot_create(app, user):
    with app.app_context():
        assert not can_create(user)
        assert scope_sql(user) == ("0=1", [])
        app.config["PERMISSIONS"] = DEMO_POLICY
        assert can_create(user)
        user["profiles"] = ["CONSULTATION"]
        assert not can_create(user)
        predicate, params = scope_sql(user)
        assert "enregistree_par_matricule = %s" in predicate
        assert params == ["00017", "00017"]
        assert scope_sql(user, "audit") == ("0=1", [])


def test_policy_does_not_silently_accept_unknown_scopes():
    with pytest.raises(ValueError):
        validate_policy({"RESPONSABLE": {"read": ["service"]}})
    with pytest.raises(ValueError):
        validate_policy({"RESPONSABLE": {"create": True}})


def test_submission_signed_and_actor_bound(app, user):
    with app.app_context():
        token = new_submission(user)
        assert len(submission_key(token, "00017")) == 64
        assert new_submission(user) != token
        with pytest.raises(FormError):
            submission_key(token, "17")
        with pytest.raises(FormError):
            submission_key(token + "changed", "00017")

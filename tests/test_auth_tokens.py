import pytest
import jwt
from django.conf import settings
from apps.auth_app.models import Role, User
from apps.auth_app.tokens import generate_access_token, generate_refresh_token, verify_refresh_token
from apps.auth_app.authentication import JWTAuthentication
from rest_framework.exceptions import AuthenticationFailed
from unittest.mock import MagicMock


@pytest.fixture
def user(db):
    role = Role.objects.create(name='admin')
    u = User(email='jwt@test.com', role=role)
    u.set_password('pass')
    u.save()
    return u


def test_generate_access_token_is_valid_jwt(user):
    token = generate_access_token(user)
    payload = jwt.decode(token, settings.SECRET_KEY, algorithms=['HS256'])
    assert payload['sub'] == user.id
    assert payload['email'] == user.email
    assert payload['role'] == 'admin'


def test_generate_access_token_expires_in_15_min(user):
    token = generate_access_token(user)
    payload = jwt.decode(token, settings.SECRET_KEY, algorithms=['HS256'])
    remaining = payload['exp'] - payload['iat']
    assert 800 <= remaining <= 920


def test_generate_refresh_token_returns_value_and_hash():
    value, hashed = generate_refresh_token()
    assert len(value) == 64
    assert hashed != value


def test_verify_refresh_token_correct():
    value, hashed = generate_refresh_token()
    assert verify_refresh_token(value, hashed) is True


def test_verify_refresh_token_wrong():
    _, hashed = generate_refresh_token()
    assert verify_refresh_token('wrongvalue', hashed) is False


@pytest.mark.django_db
def test_jwt_authentication_valid_token(user):
    token = generate_access_token(user)
    auth = JWTAuthentication()
    request = MagicMock()
    request.headers = {'Authorization': f'Bearer {token}'}
    result_user, result_token = auth.authenticate(request)
    assert result_user.id == user.id


@pytest.mark.django_db
def test_jwt_authentication_no_header():
    auth = JWTAuthentication()
    request = MagicMock()
    request.headers = {}
    assert auth.authenticate(request) is None


@pytest.mark.django_db
def test_jwt_authentication_invalid_token():
    auth = JWTAuthentication()
    request = MagicMock()
    request.headers = {'Authorization': 'Bearer not.a.token'}
    with pytest.raises(AuthenticationFailed):
        auth.authenticate(request)

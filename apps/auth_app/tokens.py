import jwt
import secrets
import bcrypt as _bcrypt
from datetime import timedelta
from django.utils import timezone
from django.conf import settings


def generate_access_token(user):
    now = timezone.now()
    payload = {
        'sub': user.id,
        'email': user.email,
        'role': user.role.name,
        'iat': int(now.timestamp()),
        'exp': int((now + timedelta(minutes=15)).timestamp()),
    }
    return jwt.encode(payload, settings.SECRET_KEY, algorithm='HS256')


def generate_refresh_token():
    value = secrets.token_hex(32)
    hashed = _bcrypt.hashpw(value.encode('utf-8'), _bcrypt.gensalt()).decode('utf-8')
    return value, hashed


def verify_refresh_token(value, hashed):
    try:
        return _bcrypt.checkpw(value.encode('utf-8'), hashed.encode('utf-8'))
    except Exception:
        return False

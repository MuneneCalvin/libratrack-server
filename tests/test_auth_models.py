import pytest
from apps.auth_app.models import Role, User, RefreshToken
from django.utils import timezone
from datetime import timedelta


@pytest.mark.django_db
def test_create_role():
    role = Role.objects.create(name='admin')
    assert role.id is not None
    assert role.name == 'admin'


@pytest.mark.django_db
def test_role_name_unique():
    from django.db import IntegrityError
    Role.objects.create(name='librarian')
    with pytest.raises(IntegrityError):
        Role.objects.create(name='librarian')


@pytest.mark.django_db
def test_create_user_with_role():
    role = Role.objects.create(name='admin')
    user = User(email='admin@test.com', role=role)
    user.set_password('Admin@1234')
    user.save()
    assert user.id is not None
    assert user.email == 'admin@test.com'
    assert user.is_active is True


@pytest.mark.django_db
def test_user_check_password_correct():
    role = Role.objects.create(name='member')
    user = User(email='alice@test.com', role=role)
    user.set_password('Secret123')
    user.save()
    assert user.check_password('Secret123') is True


@pytest.mark.django_db
def test_user_check_password_wrong():
    role = Role.objects.create(name='member')
    user = User(email='bob@test.com', role=role)
    user.set_password('Secret123')
    user.save()
    assert user.check_password('WrongPassword') is False


@pytest.mark.django_db
def test_user_email_unique():
    from django.db import IntegrityError
    role = Role.objects.create(name='member')
    User.objects.create(email='dup@test.com', role=role, password='hash')
    with pytest.raises(IntegrityError):
        User.objects.create(email='dup@test.com', role=role, password='hash')


@pytest.mark.django_db
def test_create_refresh_token():
    role = Role.objects.create(name='admin')
    user = User(email='r@test.com', role=role)
    user.set_password('pass')
    user.save()
    token = RefreshToken.objects.create(
        user=user,
        token_hash='somehash',
        expires_at=timezone.now() + timedelta(days=7),
    )
    assert token.id is not None
    assert token.revoked_at is None

import pytest
from unittest.mock import MagicMock
from apps.auth_app.models import Role, User
from apps.auth_app.permissions import IsAdmin, IsLibrarian, IsAdminOrLibrarian, IsSelfOrAdminOrLibrarian
from apps.members.models import Member


def _make_user(role_name, email):
    role = Role.objects.get_or_create(name=role_name)[0]
    u = User(email=email, role=role)
    u.set_password('pass')
    u.save()
    return u


@pytest.mark.django_db
def test_is_admin_allows_admin():
    user = _make_user('admin', 'admin@p.com')
    request = MagicMock()
    request.user = user
    assert IsAdmin().has_permission(request, None) is True


@pytest.mark.django_db
def test_is_admin_denies_librarian():
    user = _make_user('librarian', 'lib@p.com')
    request = MagicMock()
    request.user = user
    assert IsAdmin().has_permission(request, None) is False


@pytest.mark.django_db
def test_is_librarian_allows_librarian():
    user = _make_user('librarian', 'lib2@p.com')
    request = MagicMock()
    request.user = user
    assert IsLibrarian().has_permission(request, None) is True


@pytest.mark.django_db
def test_is_admin_or_librarian_allows_both():
    perm = IsAdminOrLibrarian()
    for role, email in [('admin', 'a@p.com'), ('librarian', 'l@p.com')]:
        user = _make_user(role, email)
        request = MagicMock()
        request.user = user
        assert perm.has_permission(request, None) is True


@pytest.mark.django_db
def test_is_admin_or_librarian_denies_member():
    user = _make_user('member', 'mem@p.com')
    request = MagicMock()
    request.user = user
    assert IsAdminOrLibrarian().has_permission(request, None) is False


@pytest.mark.django_db
def test_self_or_staff_allows_own_member():
    user = _make_user('member', 'self@p.com')
    member = Member.objects.create(user=user, full_name='Self', membership_number='LIB-S01')
    request = MagicMock()
    request.user = user
    assert IsSelfOrAdminOrLibrarian().has_object_permission(request, None, member) is True


@pytest.mark.django_db
def test_self_or_staff_denies_other_member():
    u1 = _make_user('member', 'u1@p.com')
    u2 = _make_user('member', 'u2@p.com')
    m2 = Member.objects.create(user=u2, full_name='Other', membership_number='LIB-S02')
    request = MagicMock()
    request.user = u1
    assert IsSelfOrAdminOrLibrarian().has_object_permission(request, None, m2) is False

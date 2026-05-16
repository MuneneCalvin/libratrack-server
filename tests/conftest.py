import pytest
from rest_framework.test import APIClient
from apps.auth_app.models import Role, User
from apps.auth_app.tokens import generate_access_token
from apps.categories.models import Category
from apps.books.models import Book
from apps.members.models import Member


@pytest.fixture
def admin_role(db):
    return Role.objects.create(name='admin')

@pytest.fixture
def librarian_role(db):
    return Role.objects.create(name='librarian')

@pytest.fixture
def member_role(db):
    return Role.objects.create(name='member')

@pytest.fixture
def admin_user(db, admin_role):
    u = User(email='admin@test.com', role=admin_role)
    u.set_password('Admin@1234')
    u.save()
    return u

@pytest.fixture
def librarian_user(db, librarian_role):
    u = User(email='librarian@test.com', role=librarian_role)
    u.set_password('Librarian@1234')
    u.save()
    return u

@pytest.fixture
def member_user(db, member_role):
    u = User(email='member@test.com', role=member_role)
    u.set_password('Member@1234')
    u.save()
    return u

@pytest.fixture
def member(db, member_user):
    return Member.objects.create(
        user=member_user, full_name='Test Member', membership_number='LIB-TEST-001'
    )

@pytest.fixture
def category(db):
    return Category.objects.create(name='Technology')

@pytest.fixture
def book(db, category):
    return Book.objects.create(
        title='Test Book', author='Test Author', isbn='978-0000000001',
        category=category, total_copies=3, available_copies=3,
    )

def _make_client(user):
    client = APIClient()
    client.credentials(HTTP_AUTHORIZATION=f'Bearer {generate_access_token(user)}')
    return client

@pytest.fixture
def admin_client(admin_user):
    return _make_client(admin_user)

@pytest.fixture
def librarian_client(librarian_user):
    return _make_client(librarian_user)

@pytest.fixture
def member_client(member_user, member):
    return _make_client(member_user)

@pytest.fixture
def anon_client():
    return APIClient()

import pytest
from rest_framework.test import APIClient
from apps.auth_app.models import Role, User


@pytest.fixture
def roles(db):
    return {
        'admin': Role.objects.create(name='admin'),
        'librarian': Role.objects.create(name='librarian'),
        'member': Role.objects.create(name='member'),
    }


@pytest.fixture
def admin_user(db, roles):
    u = User(email='admin@test.com', role=roles['admin'])
    u.set_password('Admin@1234')
    u.save()
    return u


@pytest.fixture
def client():
    return APIClient()


@pytest.mark.django_db
def test_login_success(client, admin_user):
    resp = client.post('/api/auth/login', {'email': 'admin@test.com', 'password': 'Admin@1234'}, format='json')
    assert resp.status_code == 200
    body = resp.json()
    assert body['status'] == 'success'
    assert 'accessToken' in body['data']
    assert 'refreshToken' in resp.cookies


@pytest.mark.django_db
def test_login_wrong_password(client, admin_user):
    resp = client.post('/api/auth/login', {'email': 'admin@test.com', 'password': 'wrong'}, format='json')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_login_unknown_email(client, db):
    resp = client.post('/api/auth/login', {'email': 'nobody@test.com', 'password': 'pass'}, format='json')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_me_returns_user_data(client, admin_user):
    login = client.post('/api/auth/login', {'email': 'admin@test.com', 'password': 'Admin@1234'}, format='json')
    token = login.json()['data']['accessToken']
    client.credentials(HTTP_AUTHORIZATION=f'Bearer {token}')
    resp = client.get('/api/auth/me')
    assert resp.status_code == 200
    data = resp.json()['data']
    assert data['email'] == 'admin@test.com'
    assert data['role'] == 'admin'


@pytest.mark.django_db
def test_me_unauthenticated(client):
    resp = client.get('/api/auth/me')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_refresh_issues_new_token(client, admin_user):
    login = client.post('/api/auth/login', {'email': 'admin@test.com', 'password': 'Admin@1234'}, format='json')
    cookie = login.cookies.get('refreshToken').value
    client.cookies['refreshToken'] = cookie
    resp = client.post('/api/auth/refresh')
    assert resp.status_code == 200
    assert 'accessToken' in resp.json()['data']


@pytest.mark.django_db
def test_refresh_without_cookie(client):
    resp = client.post('/api/auth/refresh')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_logout_returns_200(client, admin_user):
    login = client.post('/api/auth/login', {'email': 'admin@test.com', 'password': 'Admin@1234'}, format='json')
    token = login.json()['data']['accessToken']
    client.credentials(HTTP_AUTHORIZATION=f'Bearer {token}')
    resp = client.post('/api/auth/logout')
    assert resp.status_code == 200

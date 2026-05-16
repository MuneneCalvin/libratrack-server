import pytest


@pytest.mark.django_db
def test_list_categories_authenticated(admin_client):
    resp = admin_client.get('/api/categories/')
    assert resp.status_code == 200
    assert resp.json()['status'] == 'success'


@pytest.mark.django_db
def test_list_categories_unauthenticated(anon_client):
    resp = anon_client.get('/api/categories/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_create_category_admin(admin_client):
    resp = admin_client.post('/api/categories/', {'name': 'Fiction'}, format='json')
    assert resp.status_code == 201
    assert resp.json()['data']['name'] == 'Fiction'


@pytest.mark.django_db
def test_create_category_member_forbidden(member_client):
    resp = member_client.post('/api/categories/', {'name': 'Fiction'}, format='json')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_create_category_missing_name(admin_client):
    resp = admin_client.post('/api/categories/', {}, format='json')
    assert resp.status_code == 400


@pytest.mark.django_db
def test_get_single_category(admin_client, category):
    resp = admin_client.get(f'/api/categories/{category.id}/')
    assert resp.status_code == 200
    assert resp.json()['data']['name'] == 'Technology'


@pytest.mark.django_db
def test_update_category_librarian(librarian_client, category):
    resp = librarian_client.put(f'/api/categories/{category.id}/', {'name': 'Science'}, format='json')
    assert resp.status_code == 200
    assert resp.json()['data']['name'] == 'Science'


@pytest.mark.django_db
def test_delete_category_admin(admin_client, category):
    resp = admin_client.delete(f'/api/categories/{category.id}/')
    assert resp.status_code == 204


@pytest.mark.django_db
def test_delete_category_librarian_forbidden(librarian_client, category):
    resp = librarian_client.delete(f'/api/categories/{category.id}/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_get_nonexistent_category(admin_client):
    resp = admin_client.get('/api/categories/9999/')
    assert resp.status_code == 404

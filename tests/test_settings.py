import pytest
from apps.settings_app.models import AppSetting


@pytest.fixture
def default_settings(db):
    AppSetting.objects.create(key='fine_rate_per_day', value='5')
    AppSetting.objects.create(key='max_borrow_days', value='14')
    AppSetting.objects.create(key='max_books_per_member', value='5')
    AppSetting.objects.create(key='reservation_expiry_days', value='3')


@pytest.mark.django_db
def test_get_settings_authenticated(admin_client, default_settings):
    resp = admin_client.get('/api/settings/')
    assert resp.status_code == 200
    data = resp.json()['data']
    assert data['fine_rate_per_day'] == '5'
    assert data['max_borrow_days'] == '14'


@pytest.mark.django_db
def test_get_settings_unauthenticated(anon_client):
    resp = anon_client.get('/api/settings/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_update_settings_admin(admin_client, default_settings):
    resp = admin_client.put('/api/settings/', {'fine_rate_per_day': '10'}, format='json')
    assert resp.status_code == 200
    assert resp.json()['data']['fine_rate_per_day'] == '10'
    assert AppSetting.objects.get(key='fine_rate_per_day').value == '10'


@pytest.mark.django_db
def test_update_settings_member_forbidden(member_client, default_settings):
    resp = member_client.put('/api/settings/', {'fine_rate_per_day': '99'}, format='json')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_update_settings_librarian_forbidden(librarian_client, default_settings):
    resp = librarian_client.put('/api/settings/', {'fine_rate_per_day': '99'}, format='json')
    assert resp.status_code == 403

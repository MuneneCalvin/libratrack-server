import pytest
from apps.settings_app.models import AppSetting


@pytest.fixture
def reservation_settings(db):
    AppSetting.objects.create(key='reservation_expiry_days', value='3')


@pytest.mark.django_db
def test_list_reservations_admin(admin_client, member, book, reservation_settings):
    resp = admin_client.get('/api/reservations/')
    assert resp.status_code == 200
    assert resp.json()['status'] == 'success'


@pytest.mark.django_db
def test_list_reservations_unauthenticated(anon_client):
    resp = anon_client.get('/api/reservations/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_create_reservation_member(member_client, member, book, reservation_settings):
    resp = member_client.post('/api/reservations/', {'bookId': book.id}, format='json')
    assert resp.status_code == 201
    assert resp.json()['data']['status'] == 'PENDING'


@pytest.mark.django_db
def test_create_reservation_missing_book(member_client, reservation_settings):
    resp = member_client.post('/api/reservations/', {}, format='json')
    assert resp.status_code == 400


@pytest.mark.django_db
def test_get_reservation_detail(admin_client, member, book, reservation_settings):
    create = member_client = admin_client
    r = create.post('/api/reservations/', {'bookId': book.id, 'memberId': member.id}, format='json')
    res_id = r.json()['data']['id']
    resp = admin_client.get(f'/api/reservations/{res_id}/')
    assert resp.status_code == 200


@pytest.mark.django_db
def test_cancel_reservation(member_client, member, book, reservation_settings):
    r = member_client.post('/api/reservations/', {'bookId': book.id}, format='json')
    res_id = r.json()['data']['id']
    resp = member_client.patch(f'/api/reservations/{res_id}/cancel/')
    assert resp.status_code == 200
    assert resp.json()['data']['status'] == 'CANCELLED'


@pytest.mark.django_db
def test_fulfill_reservation_admin(admin_client, member, book, reservation_settings):
    r = admin_client.post('/api/reservations/', {'bookId': book.id, 'memberId': member.id}, format='json')
    res_id = r.json()['data']['id']
    resp = admin_client.patch(f'/api/reservations/{res_id}/fulfill/')
    assert resp.status_code == 200
    assert resp.json()['data']['status'] == 'FULFILLED'


@pytest.mark.django_db
def test_fulfill_reservation_member_forbidden(member_client, member, book, reservation_settings):
    r = member_client.post('/api/reservations/', {'bookId': book.id}, format='json')
    res_id = r.json()['data']['id']
    resp = member_client.patch(f'/api/reservations/{res_id}/fulfill/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_get_nonexistent_reservation(admin_client):
    resp = admin_client.get('/api/reservations/9999/')
    assert resp.status_code == 404

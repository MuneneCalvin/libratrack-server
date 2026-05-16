import pytest
from apps.notifications.models import Notification


@pytest.fixture
def notification(db, member_user):
    return Notification.objects.create(
        user=member_user,
        title='Test Notification',
        message='You have a test notification.',
        type='BORROW',
    )


@pytest.mark.django_db
def test_list_notifications_authenticated(member_client, notification):
    resp = member_client.get('/api/notifications/')
    assert resp.status_code == 200
    assert resp.json()['status'] == 'success'
    assert len(resp.json()['data']) == 1


@pytest.mark.django_db
def test_list_notifications_unauthenticated(anon_client):
    resp = anon_client.get('/api/notifications/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_list_notifications_only_own(admin_client, notification):
    resp = admin_client.get('/api/notifications/')
    assert resp.status_code == 200
    assert resp.json()['data'] == []


@pytest.mark.django_db
def test_mark_single_read(member_client, notification):
    resp = member_client.patch(f'/api/notifications/{notification.id}/read/')
    assert resp.status_code == 200
    notification.refresh_from_db()
    assert notification.is_read is True


@pytest.mark.django_db
def test_mark_all_read(member_client, member_user):
    Notification.objects.create(user=member_user, title='N1', message='M1', type='FINE')
    Notification.objects.create(user=member_user, title='N2', message='M2', type='FINE')
    resp = member_client.patch('/api/notifications/read-all/')
    assert resp.status_code == 200
    assert Notification.objects.filter(user=member_user, is_read=False).count() == 0


@pytest.mark.django_db
def test_mark_other_user_notification_forbidden(admin_client, notification):
    resp = admin_client.patch(f'/api/notifications/{notification.id}/read/')
    assert resp.status_code == 404

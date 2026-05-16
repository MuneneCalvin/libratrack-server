import pytest
from django.utils import timezone
from datetime import timedelta
from apps.transactions.models import BorrowTransaction
from apps.fines.models import Fine


@pytest.fixture
def overdue_tx(db, member, book):
    tx = BorrowTransaction.objects.create(
        member=member,
        borrowed_at=timezone.now() - timedelta(days=20),
        due_date=timezone.now() - timedelta(days=6),
        returned_at=timezone.now(),
        status='RETURNED',
    )
    return tx


@pytest.fixture
def fine(db, overdue_tx, member):
    return Fine.objects.create(
        transaction=overdue_tx,
        member=member,
        amount='30.00',
        reason='Returned 6 days late',
    )


@pytest.mark.django_db
def test_list_fines_admin(admin_client, fine):
    resp = admin_client.get('/api/fines/')
    assert resp.status_code == 200
    assert resp.json()['status'] == 'success'


@pytest.mark.django_db
def test_list_fines_unauthenticated(anon_client):
    resp = anon_client.get('/api/fines/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_list_fines_member_forbidden(member_client, fine):
    resp = member_client.get('/api/fines/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_get_fine_detail(admin_client, fine):
    resp = admin_client.get(f'/api/fines/{fine.id}/')
    assert resp.status_code == 200
    assert resp.json()['data']['id'] == fine.id


@pytest.mark.django_db
def test_pay_fine(admin_client, fine):
    resp = admin_client.patch(f'/api/fines/{fine.id}/pay/')
    assert resp.status_code == 200
    assert resp.json()['data']['isPaid'] is True


@pytest.mark.django_db
def test_waive_fine_admin(admin_client, fine):
    resp = admin_client.patch(f'/api/fines/{fine.id}/waive/', {'waivedNote': 'Goodwill'}, format='json')
    assert resp.status_code == 200
    assert resp.json()['data']['isWaived'] is True


@pytest.mark.django_db
def test_waive_fine_librarian_forbidden(librarian_client, fine):
    resp = librarian_client.patch(f'/api/fines/{fine.id}/waive/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_filter_fines_by_member(admin_client, fine, member):
    resp = admin_client.get(f'/api/fines/?memberId={member.id}')
    assert resp.status_code == 200
    for f in resp.json()['data']:
        assert f['memberId'] == member.id


@pytest.mark.django_db
def test_get_nonexistent_fine(admin_client):
    resp = admin_client.get('/api/fines/9999/')
    assert resp.status_code == 404

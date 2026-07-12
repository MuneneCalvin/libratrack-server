import pytest
from datetime import timedelta
from django.utils import timezone
from apps.auth_app.models import Role, User
from apps.members.models import Member
from apps.fines.models import Fine
from apps.reservations.models import Reservation
from apps.transactions.models import BorrowTransaction, TransactionItem


@pytest.mark.django_db
def test_list_members_admin(admin_client, member):
    resp = admin_client.get('/api/members/')
    assert resp.status_code == 200
    assert resp.json()['status'] == 'success'


@pytest.mark.django_db
def test_list_members_unauthenticated(anon_client):
    resp = anon_client.get('/api/members/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_list_members_member_forbidden(member_client):
    resp = member_client.get('/api/members/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_create_member_admin(admin_client, member_role):
    payload = {
        'email': 'newmember@test.com',
        'password': 'Pass@1234',
        'fullName': 'New Member',
        'membershipNumber': 'LIB-9999',
    }
    resp = admin_client.post('/api/members/', payload, format='json')
    assert resp.status_code == 201
    assert resp.json()['data']['fullName'] == 'New Member'


@pytest.mark.django_db
def test_create_member_missing_fields(admin_client):
    resp = admin_client.post('/api/members/', {'email': 'x@test.com'}, format='json')
    assert resp.status_code == 400


@pytest.mark.django_db
def test_get_member_detail_admin(admin_client, member):
    resp = admin_client.get(f'/api/members/{member.id}/')
    assert resp.status_code == 200
    assert resp.json()['data']['id'] == member.id


@pytest.mark.django_db
def test_get_member_detail_self(member_client, member):
    resp = member_client.get(f'/api/members/{member.id}/')
    assert resp.status_code == 200


@pytest.mark.django_db
def test_update_member_librarian(librarian_client, member):
    resp = librarian_client.put(f'/api/members/{member.id}/', {
        'fullName': 'Updated Name', 'membershipNumber': member.membership_number,
    }, format='json')
    assert resp.status_code == 200
    assert resp.json()['data']['fullName'] == 'Updated Name'


@pytest.mark.django_db
def test_librarian_can_revoke_member_access(librarian_client, member):
    resp = librarian_client.patch(f'/api/members/{member.id}/', {'isActive': False}, format='json')

    assert resp.status_code == 200
    member.user.refresh_from_db()
    assert member.user.is_active is False
    assert resp.json()['data']['isActive'] is False


@pytest.mark.django_db
def test_delete_member_admin(admin_client, member):
    resp = admin_client.delete(f'/api/members/{member.id}/')
    assert resp.status_code == 204


@pytest.mark.django_db
def test_delete_member_admin_removes_related_activity(admin_client, member, book):
    transaction = BorrowTransaction.objects.create(
        member=member,
        due_date=timezone.now() + timedelta(days=7),
        status='ACTIVE',
    )
    TransactionItem.objects.create(transaction=transaction, book=book)
    Fine.objects.create(transaction=transaction, member=member, amount='25.00', reason='Overdue')
    Reservation.objects.create(
        member=member,
        book=book,
        expires_at=timezone.now() + timedelta(days=3),
        status='PENDING',
    )
    member_id = member.id
    user_id = member.user_id

    resp = admin_client.delete(f'/api/members/{member_id}/')

    assert resp.status_code == 204
    assert not Member.objects.filter(id=member_id).exists()
    assert not User.objects.filter(id=user_id).exists()
    assert not BorrowTransaction.objects.filter(id=transaction.id).exists()
    assert not Fine.objects.filter(member_id=member_id).exists()
    assert not Reservation.objects.filter(member_id=member_id).exists()


@pytest.mark.django_db
def test_delete_member_librarian_forbidden(librarian_client, member):
    resp = librarian_client.delete(f'/api/members/{member.id}/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_get_nonexistent_member(admin_client):
    resp = admin_client.get('/api/members/9999/')
    assert resp.status_code == 404


@pytest.mark.django_db
def test_member_transactions_nested(admin_client, member):
    resp = admin_client.get(f'/api/members/{member.id}/transactions/')
    assert resp.status_code == 200


@pytest.mark.django_db
def test_member_fines_nested(admin_client, member):
    resp = admin_client.get(f'/api/members/{member.id}/fines/')
    assert resp.status_code == 200


@pytest.mark.django_db
def test_member_reservations_nested(admin_client, member):
    resp = admin_client.get(f'/api/members/{member.id}/reservations/')
    assert resp.status_code == 200

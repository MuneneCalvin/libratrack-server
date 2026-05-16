import pytest
from django.utils import timezone
from datetime import timedelta
from apps.transactions.models import BorrowTransaction, TransactionItem
from apps.settings_app.models import AppSetting


@pytest.fixture
def settings_data(db):
    AppSetting.objects.create(key='max_borrow_days', value='14')
    AppSetting.objects.create(key='max_books_per_member', value='5')
    AppSetting.objects.create(key='fine_rate_per_day', value='5')


@pytest.mark.django_db
def test_list_transactions_admin(admin_client, settings_data):
    resp = admin_client.get('/api/transactions/')
    assert resp.status_code == 200
    assert resp.json()['status'] == 'success'


@pytest.mark.django_db
def test_list_transactions_unauthenticated(anon_client):
    resp = anon_client.get('/api/transactions/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_list_transactions_member_forbidden(member_client):
    resp = member_client.get('/api/transactions/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_create_borrow_admin(admin_client, member, book, settings_data):
    payload = {'memberId': member.id, 'bookIds': [book.id]}
    resp = admin_client.post('/api/transactions/', payload, format='json')
    assert resp.status_code == 201
    book.refresh_from_db()
    assert book.available_copies == 2


@pytest.mark.django_db
def test_create_borrow_unavailable_book(admin_client, member, book, settings_data):
    book.available_copies = 0
    book.save()
    payload = {'memberId': member.id, 'bookIds': [book.id]}
    resp = admin_client.post('/api/transactions/', payload, format='json')
    assert resp.status_code == 400


@pytest.mark.django_db
def test_get_transaction_detail(admin_client, member, book, settings_data):
    payload = {'memberId': member.id, 'bookIds': [book.id]}
    create_resp = admin_client.post('/api/transactions/', payload, format='json')
    tx_id = create_resp.json()['data']['id']
    resp = admin_client.get(f'/api/transactions/{tx_id}/')
    assert resp.status_code == 200
    assert resp.json()['data']['id'] == tx_id


@pytest.mark.django_db
def test_return_book(admin_client, member, book, settings_data):
    borrow = admin_client.post('/api/transactions/', {'memberId': member.id, 'bookIds': [book.id]}, format='json')
    tx_id = borrow.json()['data']['id']
    resp = admin_client.post(f'/api/transactions/{tx_id}/return/')
    assert resp.status_code == 200
    assert resp.json()['data']['status'] == 'RETURNED'
    book.refresh_from_db()
    assert book.available_copies == 3


@pytest.mark.django_db
def test_return_overdue_creates_fine(admin_client, member, book, settings_data):
    from apps.fines.models import Fine
    tx = BorrowTransaction.objects.create(
        member=member,
        borrowed_at=timezone.now() - timedelta(days=20),
        due_date=timezone.now() - timedelta(days=6),
        status='ACTIVE',
    )
    TransactionItem.objects.create(transaction=tx, book=book)
    resp = admin_client.post(f'/api/transactions/{tx.id}/return/')
    assert resp.status_code == 200
    assert Fine.objects.filter(transaction=tx).exists()


@pytest.mark.django_db
def test_return_already_returned(admin_client, member, book, settings_data):
    borrow = admin_client.post('/api/transactions/', {'memberId': member.id, 'bookIds': [book.id]}, format='json')
    tx_id = borrow.json()['data']['id']
    admin_client.post(f'/api/transactions/{tx_id}/return/')
    resp = admin_client.post(f'/api/transactions/{tx_id}/return/')
    assert resp.status_code == 400


@pytest.mark.django_db
def test_filter_transactions_by_status(admin_client, member, book, settings_data):
    admin_client.post('/api/transactions/', {'memberId': member.id, 'bookIds': [book.id]}, format='json')
    resp = admin_client.get('/api/transactions/?status=ACTIVE')
    assert resp.status_code == 200
    for tx in resp.json()['data']:
        assert tx['status'] == 'ACTIVE'


@pytest.mark.django_db
def test_mark_overdue_command(member, book):
    from django.utils import timezone
    from datetime import timedelta
    from django.core.management import call_command

    tx = BorrowTransaction.objects.create(
        member=member,
        borrowed_at=timezone.now() - timedelta(days=20),
        due_date=timezone.now() - timedelta(days=5),
        status='ACTIVE',
    )
    call_command('mark_overdue')
    tx.refresh_from_db()
    assert tx.status == 'OVERDUE'


@pytest.mark.django_db
def test_mark_overdue_does_not_affect_returned(member, book):
    from django.utils import timezone
    from datetime import timedelta
    from django.core.management import call_command

    tx = BorrowTransaction.objects.create(
        member=member,
        borrowed_at=timezone.now() - timedelta(days=20),
        due_date=timezone.now() - timedelta(days=5),
        status='RETURNED',
    )
    call_command('mark_overdue')
    tx.refresh_from_db()
    assert tx.status == 'RETURNED'

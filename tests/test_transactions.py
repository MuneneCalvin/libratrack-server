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
def test_create_borrow_returns_capacity_metadata_when_member_is_at_limit(admin_client, member, book, category, settings_data):
    from apps.books.models import Book

    tx = BorrowTransaction.objects.create(member=member, due_date=timezone.now() + timedelta(days=14), status='ACTIVE')
    for index in range(5):
        borrowed_book = Book.objects.create(
            title=f'Borrowed Book {index}',
            author='Test Author',
            isbn=f'978-100000000{index}',
            category=category,
            total_copies=1,
            available_copies=0,
        )
        TransactionItem.objects.create(transaction=tx, book=borrowed_book)

    resp = admin_client.post('/api/transactions/', {'memberId': member.id, 'bookIds': [book.id]}, format='json')

    assert resp.status_code == 400
    data = resp.json()
    assert data['status'] == 'error'
    assert data['activeBorrowCount'] == 5
    assert data['maxBooks'] == 5
    assert data['remainingSlots'] == 0


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
def test_return_selected_transaction_items(admin_client, member, book, category, settings_data):
    from apps.books.models import Book

    second_book = Book.objects.create(
        title='Second Book',
        author='Second Author',
        isbn='978-0000000002',
        category=category,
        total_copies=2,
        available_copies=2,
    )
    borrow = admin_client.post('/api/transactions/', {'memberId': member.id, 'bookIds': [book.id, second_book.id]}, format='json')
    tx_id = borrow.json()['data']['id']
    first_item_id = borrow.json()['data']['items'][0]['id']

    resp = admin_client.post(f'/api/transactions/{tx_id}/return/', {'itemIds': [first_item_id]}, format='json')

    assert resp.status_code == 200
    assert resp.json()['data']['status'] == 'ACTIVE'
    returned_items = [item for item in resp.json()['data']['items'] if item['returnedAt']]
    assert [item['id'] for item in returned_items] == [first_item_id]
    book.refresh_from_db()
    second_book.refresh_from_db()
    assert book.available_copies == 3
    assert second_book.available_copies == 1


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
def test_filter_transactions_by_book(admin_client, member, book, settings_data):
    admin_client.post('/api/transactions/', {'memberId': member.id, 'bookIds': [book.id]}, format='json')
    resp = admin_client.get(f'/api/transactions/?bookId={book.id}')
    assert resp.status_code == 200
    assert resp.json()['data']
    for tx in resp.json()['data']:
        assert any(item['book']['id'] == book.id for item in tx['items'])


@pytest.mark.django_db
def test_search_transactions_by_member_or_book(admin_client, member, book, settings_data):
    admin_client.post('/api/transactions/', {'memberId': member.id, 'bookIds': [book.id]}, format='json')

    member_resp = admin_client.get('/api/transactions/?q=Test%20Member')
    book_resp = admin_client.get('/api/transactions/?q=Test%20Book')

    assert member_resp.status_code == 200
    assert book_resp.status_code == 200
    assert member_resp.json()['data']
    assert book_resp.json()['data']


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

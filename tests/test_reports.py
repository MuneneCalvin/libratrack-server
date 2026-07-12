import pytest
from django.utils import timezone
from datetime import timedelta
from apps.transactions.models import BorrowTransaction, TransactionItem
from apps.fines.models import Fine
from apps.reservations.models import Reservation


@pytest.mark.django_db
def test_summary_admin(admin_client, book, member):
    resp = admin_client.get('/api/reports/summary/')
    assert resp.status_code == 200
    data = resp.json()['data']
    assert 'totalBooks' in data
    assert data['totalCopies'] == 3
    assert data['availableBooks'] == 3
    assert data['availableCopies'] == 3
    assert data['borrowedBooks'] == 0
    assert data['reservedBooks'] == 0
    assert 'totalMembers' in data
    assert 'activeBorrows' in data
    assert 'overdueCount' in data
    assert 'unpaidFinesTotal' in data
    assert 'pendingReservations' in data


@pytest.mark.django_db
def test_summary_unauthenticated(anon_client):
    resp = anon_client.get('/api/reports/summary/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_summary_member_forbidden(member_client):
    resp = member_client.get('/api/reports/summary/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_summary_counts_pending_reservations(admin_client, book, member):
    Reservation.objects.create(
        member=member,
        book=book,
        expires_at=timezone.now() + timedelta(days=3),
        status='PENDING',
    )
    resp = admin_client.get('/api/reports/summary/')
    assert resp.status_code == 200
    assert resp.json()['data']['pendingReservations'] == 1
    assert resp.json()['data']['reservedBooks'] == 1


@pytest.mark.django_db
def test_summary_counts_active_borrowed_books(admin_client, book, member):
    tx = BorrowTransaction.objects.create(
        member=member,
        borrowed_at=timezone.now() - timedelta(days=2),
        due_date=timezone.now() + timedelta(days=12),
        status='ACTIVE',
    )
    TransactionItem.objects.create(transaction=tx, book=book)
    resp = admin_client.get('/api/reports/summary/')
    assert resp.status_code == 200
    assert resp.json()['data']['activeBorrows'] == 1
    assert resp.json()['data']['borrowedBooks'] == 1


@pytest.mark.django_db
def test_overdue_report_admin(admin_client, member, book):
    BorrowTransaction.objects.create(
        member=member,
        borrowed_at=timezone.now() - timedelta(days=20),
        due_date=timezone.now() - timedelta(days=5),
        status='OVERDUE',
    )
    resp = admin_client.get('/api/reports/overdue/')
    assert resp.status_code == 200
    assert len(resp.json()['data']) >= 1


@pytest.mark.django_db
def test_popular_books_admin(admin_client, book, member):
    tx = BorrowTransaction.objects.create(
        member=member, due_date=timezone.now() + timedelta(days=14), status='ACTIVE'
    )
    TransactionItem.objects.create(transaction=tx, book=book)
    resp = admin_client.get('/api/reports/popular-books/')
    assert resp.status_code == 200
    assert len(resp.json()['data']) >= 1


@pytest.mark.django_db
def test_member_report_admin(admin_client, member):
    resp = admin_client.get('/api/reports/members/')
    assert resp.status_code == 200
    assert resp.json()['data']['totalMembers'] == 1
    assert resp.json()['data']['activeMembers'] == 1


@pytest.mark.django_db
def test_export_report_csv(admin_client, book, member):
    resp = admin_client.post('/api/reports/export', {'type': 'csv', 'report': 'borrowing'}, format='json')
    assert resp.status_code == 200
    assert resp['Content-Type'].startswith('text/csv')
    assert b'metric,value' in resp.content


@pytest.mark.django_db
def test_overdue_report_member_forbidden(member_client):
    resp = member_client.get('/api/reports/overdue/')
    assert resp.status_code == 403

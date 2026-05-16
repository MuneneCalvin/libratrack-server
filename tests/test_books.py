import pytest
from apps.books.models import Book


@pytest.mark.django_db
def test_list_books_authenticated(admin_client, book):
    resp = admin_client.get('/api/books/')
    assert resp.status_code == 200
    body = resp.json()
    assert body['status'] == 'success'
    assert len(body['data']) >= 1


@pytest.mark.django_db
def test_list_books_unauthenticated(anon_client):
    resp = anon_client.get('/api/books/')
    assert resp.status_code == 401


@pytest.mark.django_db
def test_search_books_by_title(admin_client, book):
    resp = admin_client.get('/api/books/?search=Test')
    assert resp.status_code == 200
    assert len(resp.json()['data']) >= 1


@pytest.mark.django_db
def test_search_books_no_match(admin_client, book):
    resp = admin_client.get('/api/books/?search=zzznomatch')
    assert resp.status_code == 200
    assert resp.json()['data'] == []


@pytest.mark.django_db
def test_filter_books_by_category(admin_client, book, category):
    resp = admin_client.get(f'/api/books/?category={category.id}')
    assert resp.status_code == 200
    assert len(resp.json()['data']) >= 1


@pytest.mark.django_db
def test_create_book_admin(admin_client, category):
    payload = {
        'title': 'Clean Code', 'author': 'Robert C. Martin',
        'isbn': '978-0132350884', 'categoryId': category.id,
        'totalCopies': 3, 'availableCopies': 3,
    }
    resp = admin_client.post('/api/books/', payload, format='json')
    assert resp.status_code == 201
    assert resp.json()['data']['title'] == 'Clean Code'


@pytest.mark.django_db
def test_create_book_member_forbidden(member_client, category):
    resp = member_client.post('/api/books/', {'title': 'X', 'author': 'Y',
        'isbn': '000', 'categoryId': category.id}, format='json')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_get_book_detail(admin_client, book):
    resp = admin_client.get(f'/api/books/{book.id}/')
    assert resp.status_code == 200
    assert resp.json()['data']['id'] == book.id


@pytest.mark.django_db
def test_update_book_librarian(librarian_client, book):
    resp = librarian_client.put(f'/api/books/{book.id}/', {
        'title': 'Updated', 'author': book.author, 'isbn': book.isbn,
        'categoryId': book.category_id, 'totalCopies': 3, 'availableCopies': 3,
    }, format='json')
    assert resp.status_code == 200
    assert resp.json()['data']['title'] == 'Updated'


@pytest.mark.django_db
def test_delete_book_admin(admin_client, book):
    resp = admin_client.delete(f'/api/books/{book.id}/')
    assert resp.status_code == 204


@pytest.mark.django_db
def test_delete_book_librarian_forbidden(librarian_client, book):
    resp = librarian_client.delete(f'/api/books/{book.id}/')
    assert resp.status_code == 403


@pytest.mark.django_db
def test_get_nonexistent_book(admin_client):
    resp = admin_client.get('/api/books/9999/')
    assert resp.status_code == 404

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
def test_filter_books_by_available_true(admin_client, book, category):
    Book.objects.create(
        title='Unavailable Book',
        author='Test Author',
        isbn='978-0000000002',
        category=category,
        total_copies=1,
        available_copies=0,
    )
    resp = admin_client.get('/api/books/?available=true')
    assert resp.status_code == 200
    assert [item['title'] for item in resp.json()['data']] == ['Test Book']


@pytest.mark.django_db
def test_sort_books_by_rating(admin_client, book, category):
    book.rating_average = 3.0
    book.rating_count = 3
    book.save()
    Book.objects.create(
        title='Highly Rated',
        author='Test Author',
        isbn='978-0000000003',
        category=category,
        total_copies=1,
        available_copies=1,
        rating_average=4.8,
        rating_count=10,
    )

    resp = admin_client.get('/api/books/?sort=rating')

    assert resp.status_code == 200
    assert resp.json()['data'][0]['title'] == 'Highly Rated'


@pytest.mark.django_db
def test_sort_books_by_most_read(admin_client, book, category):
    book.already_read_count = 2
    book.save()
    Book.objects.create(
        title='Most Read',
        author='Test Author',
        isbn='978-0000000004',
        category=category,
        total_copies=1,
        available_copies=1,
        already_read_count=50,
    )

    resp = admin_client.get('/api/books/?sort=most_read')

    assert resp.status_code == 200
    assert resp.json()['data'][0]['title'] == 'Most Read'


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
    book.openlibrary_work_key = '/works/OL1W'
    book.synopsis = 'A short synopsis.'
    book.subjects = ['Software', 'Testing']
    book.language_codes = ['eng', 'spa']
    book.edition_count = 4
    book.rating_average = 4.5
    book.rating_count = 10
    book.want_to_read_count = 20
    book.currently_reading_count = 3
    book.already_read_count = 7
    book.save()

    resp = admin_client.get(f'/api/books/{book.id}/')
    assert resp.status_code == 200
    data = resp.json()['data']
    assert data['id'] == book.id
    assert data['openLibraryWorkKey'] == '/works/OL1W'
    assert data['synopsis'] == 'A short synopsis.'
    assert data['subjects'] == ['Software', 'Testing']
    assert data['languageCodes'] == ['eng', 'spa']
    assert data['editionCount'] == 4
    assert data['ratingAverage'] == 4.5
    assert data['ratingCount'] == 10
    assert data['wantToReadCount'] == 20
    assert data['currentlyReadingCount'] == 3
    assert data['alreadyReadCount'] == 7


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

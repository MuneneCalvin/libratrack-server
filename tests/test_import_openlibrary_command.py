import pytest
from django.core.management import call_command
from django.core.management.base import CommandError

from apps.books.management.commands import import_openlibrary_books as command_module
from apps.books.models import Book
from apps.categories.models import Category


def make_doc(title, isbn):
    return {
        'title': title,
        'author_name': ['Demo Author'],
        'isbn': [isbn],
        'publisher': ['Demo Publisher'],
        'first_publish_year': 2020,
        'cover_i': 12345,
    }


@pytest.mark.django_db
def test_import_command_creates_books_with_default_copy_count(monkeypatch):
    docs = [
        make_doc('Imported One', '9780000000001'),
        make_doc('Imported Two', '9780000000002'),
    ]

    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        return docs if query == 'fiction' and page == 1 else []

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)

    call_command('import_openlibrary_books', limit=2, copies=50)

    assert Book.objects.count() == 2
    first = Book.objects.get(isbn='9780000000001')
    assert first.title == 'Imported One'
    assert first.author == 'Demo Author'
    assert first.category.name == 'Fiction'
    assert first.total_copies == 50
    assert first.available_copies == 50
    assert first.publisher == 'Demo Publisher'
    assert first.published_year == 2020
    assert first.cover_url == 'https://covers.openlibrary.org/b/isbn/9780000000001-L.jpg'


@pytest.mark.django_db
def test_import_command_enriches_books_with_work_metadata(monkeypatch):
    doc = make_doc('Enriched Book', '9780000000111')
    doc.update(
        {
            'key': '/works/OL111W',
            'subject': ['Search Subject'],
            'language': ['eng', 'fre'],
            'edition_count': 7,
            'ratings_average': 4.25,
            'ratings_count': 12,
            'want_to_read_count': 30,
            'currently_reading_count': 4,
            'already_read_count': 8,
        }
    )

    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        return [doc] if query == 'fiction' and page == 1 else []

    def fake_fetch_work(work_key, timeout=30, http_client='urllib'):
        assert work_key == '/works/OL111W'
        return {
            'description': 'A useful synopsis for members.',
            'subjects': ['Work Subject', 'Search Subject'],
        }

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)
    monkeypatch.setattr(command_module, 'fetch_openlibrary_work', fake_fetch_work)

    call_command('import_openlibrary_books', limit=1, copies=50)

    book = Book.objects.get(isbn='9780000000111')
    assert book.openlibrary_work_key == '/works/OL111W'
    assert book.synopsis == 'A useful synopsis for members.'
    assert book.subjects == ['Search Subject', 'Work Subject']
    assert book.language_codes == ['eng', 'fre']
    assert book.edition_count == 7
    assert book.rating_average == 4.25
    assert book.rating_count == 12
    assert book.want_to_read_count == 30
    assert book.currently_reading_count == 4
    assert book.already_read_count == 8


@pytest.mark.django_db
def test_import_command_can_skip_work_detail_requests(monkeypatch):
    doc = make_doc('Fast Import', '9780000000112')
    doc['key'] = '/works/OL112W'

    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        return [doc] if query == 'fiction' and page == 1 else []

    def fail_fetch_work(work_key):
        raise AssertionError('work details should not be fetched')

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)
    monkeypatch.setattr(command_module, 'fetch_openlibrary_work', fail_fetch_work)

    call_command('import_openlibrary_books', limit=1, copies=50, skip_work_details=True)

    book = Book.objects.get(isbn='9780000000112')
    assert book.openlibrary_work_key == '/works/OL112W'
    assert book.synopsis is None


@pytest.mark.django_db
def test_import_command_skips_duplicate_isbns(monkeypatch):
    category = Category.objects.create(name='Fiction')
    Book.objects.create(
        title='Existing Book',
        author='Existing Author',
        isbn='9780000000001',
        category=category,
        total_copies=3,
        available_copies=3,
    )

    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        return [make_doc('Duplicate Book', '9780000000001')] if page == 1 else []

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)

    call_command('import_openlibrary_books', limit=1, copies=50)

    assert Book.objects.count() == 1
    existing = Book.objects.get(isbn='9780000000001')
    assert existing.title == 'Existing Book'
    assert existing.total_copies == 3
    assert existing.available_copies == 3


@pytest.mark.django_db
def test_import_command_skips_invalid_results(monkeypatch):
    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        return [{'title': 'Missing ISBN', 'author_name': ['Demo Author']}] if page == 1 else []

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)

    call_command('import_openlibrary_books', limit=1, copies=50)

    assert Book.objects.count() == 0


@pytest.mark.django_db
def test_import_command_stops_at_limit(monkeypatch):
    docs = [
        make_doc('Imported One', '9780000000001'),
        make_doc('Imported Two', '9780000000002'),
        make_doc('Imported Three', '9780000000003'),
    ]

    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        return docs if query == 'fiction' and page == 1 else []

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)

    call_command('import_openlibrary_books', limit=2, copies=50)

    assert Book.objects.count() == 2
    assert not Book.objects.filter(isbn='9780000000003').exists()


@pytest.mark.django_db
def test_import_command_continues_after_source_failure(monkeypatch):
    calls = []

    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        calls.append((query, page))
        if query == 'fiction':
            raise TimeoutError('network timeout')
        if query == 'classic literature' and page == 1:
            return [make_doc('Classic Import', '9780000000099')]
        return []

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)
    monkeypatch.setattr(command_module.time, 'sleep', lambda seconds: None)

    call_command('import_openlibrary_books', limit=1, copies=50)

    assert Book.objects.count() == 1
    assert Book.objects.get().title == 'Classic Import'
    assert ('fiction', 1) in calls
    assert ('classic literature', 1) in calls


@pytest.mark.django_db
def test_import_command_passes_network_options_to_fetcher(monkeypatch):
    captured = {}

    def fake_fetch(query, page=1, limit=100, timeout=30, http_client='urllib'):
        captured['query'] = query
        captured['page'] = page
        captured['limit'] = limit
        captured['timeout'] = timeout
        captured['http_client'] = http_client
        return [make_doc('Tuned Import', '9780000000101')]

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)

    call_command(
        'import_openlibrary_books',
        limit=1,
        copies=50,
        page_size=25,
        timeout=60,
        retries=2,
        http_client='curl',
        skip_work_details=True,
    )

    assert Book.objects.count() == 1
    assert captured == {
        'query': 'fiction',
        'page': 1,
        'limit': 25,
        'timeout': 60,
        'http_client': 'curl',
    }


def test_import_command_rejects_invalid_limit():
    with pytest.raises(CommandError, match='--limit must be greater than 0'):
        call_command('import_openlibrary_books', limit=0, copies=50)


def test_import_command_rejects_invalid_copies():
    with pytest.raises(CommandError, match='--copies must be greater than 0'):
        call_command('import_openlibrary_books', limit=1, copies=0)


def test_import_command_rejects_invalid_page_size():
    with pytest.raises(CommandError, match='--page-size must be greater than 0'):
        call_command('import_openlibrary_books', limit=1, copies=50, page_size=0)


def test_import_command_rejects_invalid_timeout():
    with pytest.raises(CommandError, match='--timeout must be greater than 0'):
        call_command('import_openlibrary_books', limit=1, copies=50, timeout=0)


def test_import_command_rejects_invalid_retries():
    with pytest.raises(CommandError, match='--retries must be greater than 0'):
        call_command('import_openlibrary_books', limit=1, copies=50, retries=0)

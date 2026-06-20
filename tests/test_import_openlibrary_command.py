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

    def fake_fetch(query, page=1, limit=100, timeout=10):
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

    def fake_fetch(query, page=1, limit=100, timeout=10):
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
    def fake_fetch(query, page=1, limit=100, timeout=10):
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

    def fake_fetch(query, page=1, limit=100, timeout=10):
        return docs if query == 'fiction' and page == 1 else []

    monkeypatch.setattr(command_module, 'fetch_openlibrary_docs', fake_fetch)

    call_command('import_openlibrary_books', limit=2, copies=50)

    assert Book.objects.count() == 2
    assert not Book.objects.filter(isbn='9780000000003').exists()


@pytest.mark.django_db
def test_import_command_continues_after_source_failure(monkeypatch):
    calls = []

    def fake_fetch(query, page=1, limit=100, timeout=10):
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


def test_import_command_rejects_invalid_limit():
    with pytest.raises(CommandError, match='--limit must be greater than 0'):
        call_command('import_openlibrary_books', limit=0, copies=50)


def test_import_command_rejects_invalid_copies():
    with pytest.raises(CommandError, match='--copies must be greater than 0'):
        call_command('import_openlibrary_books', limit=1, copies=0)

import json

from apps.books import openlibrary_importer as importer


def test_choose_isbn_prefers_isbn_13():
    assert importer.choose_isbn(['0-13-235088-2', '978-0132350884']) == '9780132350884'


def test_choose_isbn_falls_back_to_isbn_10():
    assert importer.choose_isbn(['0-13-235088-2']) == '0132350882'


def test_build_cover_url_prefers_isbn():
    assert (
        importer.build_cover_url('9780132350884', 12345)
        == 'https://covers.openlibrary.org/b/isbn/9780132350884-L.jpg'
    )


def test_build_cover_url_falls_back_to_cover_id():
    assert (
        importer.build_cover_url(None, 12345)
        == 'https://covers.openlibrary.org/b/id/12345-L.jpg'
    )


def test_normalize_openlibrary_doc_maps_book_fields():
    doc = {
        'title': 'Clean Code',
        'author_name': ['Robert C. Martin'],
        'isbn': ['0132350882', '9780132350884'],
        'publisher': ['Prentice Hall'],
        'first_publish_year': 2008,
        'cover_i': 12345,
    }

    candidate = importer.normalize_openlibrary_doc(doc, 'Technology')

    assert candidate is not None
    assert candidate.title == 'Clean Code'
    assert candidate.author == 'Robert C. Martin'
    assert candidate.isbn == '9780132350884'
    assert candidate.category_name == 'Technology'
    assert candidate.publisher == 'Prentice Hall'
    assert candidate.published_year == 2008
    assert candidate.cover_url == 'https://covers.openlibrary.org/b/isbn/9780132350884-L.jpg'


def test_normalize_openlibrary_doc_skips_missing_required_fields():
    assert importer.normalize_openlibrary_doc({'title': 'No ISBN', 'author_name': ['A']}, 'Fiction') is None
    assert importer.normalize_openlibrary_doc({'isbn': ['9780132350884'], 'author_name': ['A']}, 'Fiction') is None
    assert importer.normalize_openlibrary_doc({'title': 'No Author', 'isbn': ['9780132350884']}, 'Fiction') is None


def test_fetch_openlibrary_docs_builds_search_request(monkeypatch):
    captured = {}

    class FakeResponse:
        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, traceback):
            return False

        def read(self):
            return json.dumps({'docs': [{'title': 'Example'}]}).encode('utf-8')

    def fake_urlopen(request, timeout):
        captured['url'] = request.full_url
        captured['timeout'] = timeout
        captured['user_agent'] = request.get_header('User-agent')
        return FakeResponse()

    monkeypatch.setattr(importer, 'urlopen', fake_urlopen)

    docs = importer.fetch_openlibrary_docs('programming', page=2, limit=25, timeout=7)

    assert docs == [{'title': 'Example'}]
    assert captured['url'].startswith('https://openlibrary.org/search.json?')
    assert 'q=programming' in captured['url']
    assert 'page=2' in captured['url']
    assert 'limit=25' in captured['url']
    assert 'fields=title%2Cauthor_name%2Cisbn%2Cpublisher%2Cfirst_publish_year%2Ccover_i' in captured['url']
    assert captured['timeout'] == 7
    assert captured['user_agent'] == 'LibraTrack Open Library importer'

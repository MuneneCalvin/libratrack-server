import json
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlencode
from urllib.request import Request, urlopen


OPEN_LIBRARY_SEARCH_URL = 'https://openlibrary.org/search.json'
OPEN_LIBRARY_COVER_BASE_URL = 'https://covers.openlibrary.org/b'
SEARCH_FIELDS = 'title,author_name,isbn,publisher,first_publish_year,cover_i'
MAX_TITLE_LENGTH = 500
MAX_AUTHOR_LENGTH = 500
MAX_PUBLISHER_LENGTH = 255

TOPIC_QUERIES = (
    ('Fiction', 'fiction'),
    ('Classics', 'classic literature'),
    ('Science', 'science'),
    ('Technology', 'technology'),
    ('Programming', 'computer programming'),
    ('Business', 'business'),
    ('History', 'history'),
    ('Biography', 'biography'),
    ('Education', 'education'),
    ('Health', 'health'),
    ('Children', 'children books'),
    ('Literature', 'literature'),
)


@dataclass(frozen=True)
class OpenLibraryBookCandidate:
    title: str
    author: str
    isbn: str
    category_name: str
    publisher: str | None = None
    published_year: int | None = None
    cover_url: str | None = None


def _clean_text(value: Any, max_length: int) -> str:
    return str(value).strip()[:max_length]


def _first_text(values: Any, max_length: int) -> str | None:
    if isinstance(values, list):
        for value in values:
            cleaned = _clean_text(value, max_length)
            if cleaned:
                return cleaned
        return None
    cleaned = _clean_text(values, max_length) if values is not None else ''
    return cleaned or None


def _normalize_isbn(value: Any) -> str:
    return ''.join(char for char in str(value).upper() if char.isdigit() or char == 'X')


def _is_isbn_13(value: str) -> bool:
    return len(value) == 13 and value.isdigit()


def _is_isbn_10(value: str) -> bool:
    return len(value) == 10 and value[:9].isdigit() and (value[9].isdigit() or value[9] == 'X')


def choose_isbn(isbns: list[str] | None) -> str | None:
    normalized = [_normalize_isbn(isbn) for isbn in isbns or []]
    for isbn in normalized:
        if _is_isbn_13(isbn):
            return isbn
    for isbn in normalized:
        if _is_isbn_10(isbn):
            return isbn
    return None


def build_cover_url(isbn: str | None, cover_id: object) -> str | None:
    if isbn:
        return f'{OPEN_LIBRARY_COVER_BASE_URL}/isbn/{isbn}-L.jpg'
    if cover_id:
        return f'{OPEN_LIBRARY_COVER_BASE_URL}/id/{cover_id}-L.jpg'
    return None


def normalize_openlibrary_doc(
    doc: dict[str, object],
    category_name: str,
) -> OpenLibraryBookCandidate | None:
    title = _first_text(doc.get('title'), MAX_TITLE_LENGTH)
    author_names = doc.get('author_name')
    author = None
    if isinstance(author_names, list):
        authors = [_clean_text(author, MAX_AUTHOR_LENGTH) for author in author_names]
        author = ', '.join(author for author in authors if author)[:MAX_AUTHOR_LENGTH]

    isbn_values = doc.get('isbn')
    isbn = choose_isbn(isbn_values if isinstance(isbn_values, list) else None)

    if not title or not author or not isbn:
        return None

    publisher = _first_text(doc.get('publisher'), MAX_PUBLISHER_LENGTH)
    published_year = doc.get('first_publish_year')
    if not isinstance(published_year, int):
        published_year = None

    return OpenLibraryBookCandidate(
        title=title,
        author=author,
        isbn=isbn,
        category_name=category_name,
        publisher=publisher,
        published_year=published_year,
        cover_url=build_cover_url(isbn, doc.get('cover_i')),
    )


def fetch_openlibrary_docs(
    query: str,
    page: int = 1,
    limit: int = 100,
    timeout: int = 10,
) -> list[dict[str, object]]:
    params = urlencode(
        {
            'q': query,
            'page': page,
            'limit': limit,
            'fields': SEARCH_FIELDS,
        }
    )
    request = Request(
        f'{OPEN_LIBRARY_SEARCH_URL}?{params}',
        headers={'User-Agent': 'LibraTrack Open Library importer'},
    )
    with urlopen(request, timeout=timeout) as response:
        payload = json.loads(response.read().decode('utf-8'))

    docs = payload.get('docs', [])
    return docs if isinstance(docs, list) else []

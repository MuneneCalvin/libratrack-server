import json
import re
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlencode
from urllib.request import Request, urlopen


OPEN_LIBRARY_SEARCH_URL = 'https://openlibrary.org/search.json'
OPEN_LIBRARY_BASE_URL = 'https://openlibrary.org'
OPEN_LIBRARY_COVER_BASE_URL = 'https://covers.openlibrary.org/b'
SEARCH_FIELDS = ','.join(
    [
        'key',
        'title',
        'author_name',
        'isbn',
        'publisher',
        'first_publish_year',
        'cover_i',
        'subject',
        'language',
        'edition_count',
        'ratings_average',
        'ratings_count',
        'want_to_read_count',
        'currently_reading_count',
        'already_read_count',
    ]
)
MAX_TITLE_LENGTH = 500
MAX_AUTHOR_LENGTH = 500
MAX_PUBLISHER_LENGTH = 255
MAX_WORK_KEY_LENGTH = 64
MAX_SYNOPSIS_LENGTH = 3000
MAX_SUBJECT_LENGTH = 80
MAX_SUBJECTS = 12
MAX_LANGUAGES = 8
PHYSICAL_DESCRIPTION_PATTERN = re.compile(
    r'\b(\d+\s*)?(pages?|p\.)\b.*\bcm\b',
    re.IGNORECASE,
)

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
    openlibrary_work_key: str | None = None
    synopsis: str | None = None
    subjects: list[str] | None = None
    language_codes: list[str] | None = None
    edition_count: int = 0
    rating_average: float | None = None
    rating_count: int = 0
    want_to_read_count: int = 0
    currently_reading_count: int = 0
    already_read_count: int = 0


def _clean_text(value: Any, max_length: int) -> str:
    if value is None:
        return ''
    return ' '.join(str(value).split())[:max_length]


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


def _positive_int(value: Any) -> int:
    return value if isinstance(value, int) and value > 0 else 0


def _positive_float(value: Any) -> float | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, (float, int)) and value > 0:
        return float(value)
    return None


def _clean_unique_list(values: Any, max_items: int, max_length: int) -> list[str]:
    if not isinstance(values, list):
        return []

    cleaned_values = []
    seen = set()
    for value in values:
        cleaned = _clean_text(value, max_length)
        key = cleaned.lower()
        if cleaned and key not in seen:
            cleaned_values.append(cleaned)
            seen.add(key)
        if len(cleaned_values) >= max_items:
            break
    return cleaned_values


def _merge_unique_lists(
    primary: list[str] | None,
    secondary: list[str] | None,
    max_items: int,
) -> list[str]:
    merged = []
    seen = set()
    for values in (primary or [], secondary or []):
        for value in values:
            cleaned = _clean_text(value, MAX_SUBJECT_LENGTH)
            key = cleaned.lower()
            if cleaned and key not in seen:
                merged.append(cleaned)
                seen.add(key)
            if len(merged) >= max_items:
                break
        if len(merged) >= max_items:
            break
    return merged


def _text_value(value: Any, max_length: int) -> str | None:
    if isinstance(value, dict):
        return _text_value(value.get('value'), max_length)
    cleaned = _clean_text(value, max_length)
    return cleaned or None


def _extract_synopsis(work: dict[str, object]) -> str | None:
    description = _text_value(work.get('description'), MAX_SYNOPSIS_LENGTH)
    if description and not _looks_like_physical_description(description):
        return description

    first_sentence = _text_value(work.get('first_sentence'), MAX_SYNOPSIS_LENGTH)
    if first_sentence:
        return first_sentence

    excerpts = work.get('excerpts')
    if isinstance(excerpts, list):
        for excerpt in excerpts:
            if not isinstance(excerpt, dict):
                continue
            text = _text_value(excerpt.get('excerpt'), MAX_SYNOPSIS_LENGTH)
            if text:
                return text
    return None


def _looks_like_physical_description(value: str) -> bool:
    return len(value) < 160 and bool(PHYSICAL_DESCRIPTION_PATTERN.search(value))


def _normalize_work_key(value: Any) -> str | None:
    cleaned = _clean_text(value, MAX_WORK_KEY_LENGTH)
    if cleaned.startswith('/works/'):
        return cleaned
    if cleaned.startswith('OL') and cleaned.endswith('W'):
        return f'/works/{cleaned}'
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
        openlibrary_work_key=_normalize_work_key(doc.get('key')),
        subjects=_clean_unique_list(doc.get('subject'), MAX_SUBJECTS, MAX_SUBJECT_LENGTH),
        language_codes=_clean_unique_list(doc.get('language'), MAX_LANGUAGES, 12),
        edition_count=_positive_int(doc.get('edition_count')),
        rating_average=_positive_float(doc.get('ratings_average')),
        rating_count=_positive_int(doc.get('ratings_count')),
        want_to_read_count=_positive_int(doc.get('want_to_read_count')),
        currently_reading_count=_positive_int(doc.get('currently_reading_count')),
        already_read_count=_positive_int(doc.get('already_read_count')),
    )


def enrich_candidate_with_work_details(
    candidate: OpenLibraryBookCandidate,
    work: dict[str, object],
) -> OpenLibraryBookCandidate:
    subjects = _merge_unique_lists(
        candidate.subjects,
        _clean_unique_list(work.get('subjects'), MAX_SUBJECTS, MAX_SUBJECT_LENGTH),
        MAX_SUBJECTS,
    )
    return OpenLibraryBookCandidate(
        title=candidate.title,
        author=candidate.author,
        isbn=candidate.isbn,
        category_name=candidate.category_name,
        publisher=candidate.publisher,
        published_year=candidate.published_year,
        cover_url=candidate.cover_url,
        openlibrary_work_key=candidate.openlibrary_work_key,
        synopsis=candidate.synopsis or _extract_synopsis(work),
        subjects=subjects,
        language_codes=candidate.language_codes,
        edition_count=candidate.edition_count,
        rating_average=candidate.rating_average,
        rating_count=candidate.rating_count,
        want_to_read_count=candidate.want_to_read_count,
        currently_reading_count=candidate.currently_reading_count,
        already_read_count=candidate.already_read_count,
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
    if not isinstance(docs, list):
        return []
    return [doc for doc in docs if isinstance(doc, dict)]


def fetch_openlibrary_work(
    work_key: str,
    timeout: int = 10,
) -> dict[str, object]:
    normalized_key = _normalize_work_key(work_key)
    if normalized_key is None:
        return {}

    request = Request(
        f'{OPEN_LIBRARY_BASE_URL}{normalized_key}.json',
        headers={'User-Agent': 'LibraTrack Open Library importer'},
    )
    with urlopen(request, timeout=timeout) as response:
        payload = json.loads(response.read().decode('utf-8'))

    return payload if isinstance(payload, dict) else {}

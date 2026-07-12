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
        'key': '/works/OL17618370W',
        'title': 'Clean Code',
        'author_name': ['Robert C. Martin'],
        'isbn': ['0132350882', '9780132350884'],
        'publisher': ['Prentice Hall'],
        'first_publish_year': 2008,
        'cover_i': 12345,
        'subject': ['Computer software', 'Agile software development'],
        'language': ['eng', 'spa'],
        'edition_count': 13,
        'ratings_average': 4.46,
        'ratings_count': 41,
        'want_to_read_count': 823,
        'currently_reading_count': 35,
        'already_read_count': 61,
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
    assert candidate.openlibrary_work_key == '/works/OL17618370W'
    assert candidate.subjects == ['Computer software', 'Agile software development']
    assert candidate.language_codes == ['eng', 'spa']
    assert candidate.edition_count == 13
    assert candidate.rating_average == 4.46
    assert candidate.rating_count == 41
    assert candidate.want_to_read_count == 823
    assert candidate.currently_reading_count == 35
    assert candidate.already_read_count == 61


def test_normalize_openlibrary_doc_skips_missing_required_fields():
    assert importer.normalize_openlibrary_doc({'title': 'No ISBN', 'author_name': ['A']}, 'Fiction') is None
    assert importer.normalize_openlibrary_doc({'isbn': ['9780132350884'], 'author_name': ['A']}, 'Fiction') is None
    assert importer.normalize_openlibrary_doc({'title': 'No Author', 'isbn': ['9780132350884']}, 'Fiction') is None


def test_enrich_candidate_with_work_details_adds_synopsis_and_subjects():
    candidate = importer.normalize_openlibrary_doc(
        {
            'key': '/works/OL17618370W',
            'title': 'Clean Code',
            'author_name': ['Robert C. Martin'],
            'isbn': ['9780132350884'],
            'subject': ['Computer software'],
        },
        'Technology',
    )

    enriched = importer.enrich_candidate_with_work_details(
        candidate,
        {
            'description': {'type': '/type/text', 'value': 'A practical guide to cleaner software.'},
            'subjects': ['Software design', 'Computer software'],
        },
    )

    assert enriched.synopsis == 'A practical guide to cleaner software.'
    assert enriched.subjects == ['Computer software', 'Software design']


def test_enrich_candidate_uses_first_sentence_when_description_missing():
    candidate = importer.normalize_openlibrary_doc(
        {
            'key': '/works/OL1W',
            'title': 'Example',
            'author_name': ['Demo Author'],
            'isbn': ['9780132350884'],
        },
        'Fiction',
    )

    enriched = importer.enrich_candidate_with_work_details(
        candidate,
        {'first_sentence': {'type': '/type/text', 'value': 'The first sentence.'}},
    )

    assert enriched.synopsis == 'The first sentence.'


def test_enrich_candidate_ignores_physical_description():
    candidate = importer.normalize_openlibrary_doc(
        {
            'key': '/works/OL2W',
            'title': 'Example',
            'author_name': ['Demo Author'],
            'isbn': ['9780132350884'],
        },
        'Fiction',
    )

    enriched = importer.enrich_candidate_with_work_details(
        candidate,
        {
            'description': 'ix, 340 pages : 20 cm',
            'first_sentence': {'type': '/type/text', 'value': 'A better teaser.'},
        },
    )

    assert enriched.synopsis == 'A better teaser.'


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
    assert 'fields=key%2Ctitle%2Cauthor_name%2Cisbn%2Cpublisher%2Cfirst_publish_year%2Ccover_i%2Csubject%2Clanguage%2Cedition_count%2Cratings_average%2Cratings_count%2Cwant_to_read_count%2Ccurrently_reading_count%2Calready_read_count' in captured['url']
    assert captured['timeout'] == 7
    assert captured['user_agent'] == 'LibraTrack Open Library importer'


def test_fetch_openlibrary_docs_filters_malformed_results(monkeypatch):
    class FakeResponse:
        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, traceback):
            return False

        def read(self):
            return json.dumps({'docs': [{'title': 'Example'}, None, 'bad']}).encode('utf-8')

    monkeypatch.setattr(importer, 'urlopen', lambda request, timeout: FakeResponse())

    assert importer.fetch_openlibrary_docs('programming') == [{'title': 'Example'}]


def test_fetch_openlibrary_docs_can_use_curl(monkeypatch):
    captured = {}

    class FakeCompletedProcess:
        returncode = 0
        stdout = json.dumps({'docs': [{'title': 'Curl Example'}]})
        stderr = ''

    def fake_run(command, capture_output, check, text, timeout):
        captured['command'] = command
        captured['capture_output'] = capture_output
        captured['check'] = check
        captured['text'] = text
        captured['timeout'] = timeout
        return FakeCompletedProcess()

    monkeypatch.setattr(importer.subprocess, 'run', fake_run)

    docs = importer.fetch_openlibrary_docs(
        'programming',
        page=2,
        limit=25,
        timeout=7,
        http_client='curl',
    )

    assert docs == [{'title': 'Curl Example'}]
    assert captured['command'][0] == 'curl'
    assert '--max-time' in captured['command']
    assert '7' in captured['command']
    assert '-A' in captured['command']
    assert importer.OPEN_LIBRARY_USER_AGENT in captured['command']
    assert 'q=programming' in captured['command'][-1]
    assert 'page=2' in captured['command'][-1]
    assert 'limit=25' in captured['command'][-1]
    assert captured['capture_output'] is True
    assert captured['check'] is False
    assert captured['text'] is True
    assert captured['timeout'] == 12


def test_fetch_openlibrary_docs_auto_uses_curl_when_available(monkeypatch):
    calls = {'curl': 0, 'urllib': 0}

    def fake_curl(url, timeout):
        calls['curl'] += 1
        return {'docs': [{'title': 'Auto Curl'}]}

    def fake_urllib(url, timeout):
        calls['urllib'] += 1
        return {'docs': [{'title': 'Auto Urllib'}]}

    monkeypatch.setattr(importer.shutil, 'which', lambda command: '/usr/bin/curl')
    monkeypatch.setattr(importer, '_load_json_url_with_curl', fake_curl)
    monkeypatch.setattr(importer, '_load_json_url_with_urllib', fake_urllib)

    assert importer.fetch_openlibrary_docs('programming', http_client='auto') == [
        {'title': 'Auto Curl'}
    ]
    assert calls == {'curl': 1, 'urllib': 0}


def test_fetch_openlibrary_work_builds_work_request(monkeypatch):
    captured = {}

    class FakeResponse:
        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, traceback):
            return False

        def read(self):
            return json.dumps({'description': 'Demo'}).encode('utf-8')

    def fake_urlopen(request, timeout):
        captured['url'] = request.full_url
        captured['timeout'] = timeout
        captured['user_agent'] = request.get_header('User-agent')
        return FakeResponse()

    monkeypatch.setattr(importer, 'urlopen', fake_urlopen)

    work = importer.fetch_openlibrary_work('/works/OL17618370W', timeout=6)

    assert work == {'description': 'Demo'}
    assert captured['url'] == 'https://openlibrary.org/works/OL17618370W.json'
    assert captured['timeout'] == 6
    assert captured['user_agent'] == 'LibraTrack Open Library importer'

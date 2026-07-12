import time

from django.core.management.base import BaseCommand, CommandError

from apps.books.models import Book
from apps.books.openlibrary_importer import (
    TOPIC_QUERIES,
    enrich_candidate_with_work_details,
    fetch_openlibrary_docs,
    fetch_openlibrary_work,
    normalize_openlibrary_doc,
)
from apps.categories.models import Category


class Command(BaseCommand):
    help = 'Import book metadata from Open Library into the local LibraTrack catalog'

    def add_arguments(self, parser):
        parser.add_argument('--limit', type=int, default=500)
        parser.add_argument('--copies', type=int, default=50)
        parser.add_argument('--page-size', type=int, default=50)
        parser.add_argument('--timeout', type=int, default=30)
        parser.add_argument('--retries', type=int, default=5)
        parser.add_argument(
            '--http-client',
            choices=['auto', 'urllib', 'curl'],
            default='auto',
            help='HTTP client used for Open Library requests. Auto prefers curl when available.',
        )
        parser.add_argument(
            '--skip-work-details',
            action='store_true',
            help='Skip per-work Open Library detail requests for faster imports.',
        )

    def handle(self, *args, **options):
        target = options['limit']
        copies = options['copies']
        page_size = options['page_size']
        timeout = options['timeout']
        retries = options['retries']
        http_client = options['http_client']
        skip_work_details = options['skip_work_details']

        if target < 1:
            raise CommandError('--limit must be greater than 0')
        if copies < 1:
            raise CommandError('--copies must be greater than 0')
        if page_size < 1:
            raise CommandError('--page-size must be greater than 0')
        if timeout < 1:
            raise CommandError('--timeout must be greater than 0')
        if retries < 1:
            raise CommandError('--retries must be greater than 0')

        summary = {
            'imported': 0,
            'duplicates': 0,
            'invalid': 0,
            'categories_created': 0,
            'details_failed': 0,
        }

        for category_name, query in TOPIC_QUERIES:
            if summary['imported'] >= target:
                break

            category, created = Category.objects.get_or_create(name=category_name)
            if created:
                summary['categories_created'] += 1

            page = 1
            while summary['imported'] < target:
                docs = self._fetch_with_retries(
                    query,
                    page,
                    page_size=page_size,
                    timeout=timeout,
                    retries=retries,
                    http_client=http_client,
                )
                if not docs:
                    break

                for doc in docs:
                    if summary['imported'] >= target:
                        break

                    candidate = normalize_openlibrary_doc(doc, category_name)
                    if candidate is None:
                        summary['invalid'] += 1
                        continue

                    if Book.objects.filter(isbn=candidate.isbn).exists():
                        summary['duplicates'] += 1
                        continue

                    if candidate.openlibrary_work_key and not skip_work_details:
                        work = self._fetch_work_with_retries(
                            candidate.openlibrary_work_key,
                            timeout=timeout,
                            retries=retries,
                            http_client=http_client,
                        )
                        if work:
                            candidate = enrich_candidate_with_work_details(candidate, work)
                        else:
                            summary['details_failed'] += 1

                    Book.objects.create(
                        title=candidate.title,
                        author=candidate.author,
                        isbn=candidate.isbn,
                        category=category,
                        total_copies=copies,
                        available_copies=copies,
                        publisher=candidate.publisher,
                        published_year=candidate.published_year,
                        cover_url=candidate.cover_url,
                        openlibrary_work_key=candidate.openlibrary_work_key,
                        synopsis=candidate.synopsis,
                        subjects=candidate.subjects or [],
                        language_codes=candidate.language_codes or [],
                        edition_count=candidate.edition_count,
                        rating_average=candidate.rating_average,
                        rating_count=candidate.rating_count,
                        want_to_read_count=candidate.want_to_read_count,
                        currently_reading_count=candidate.currently_reading_count,
                        already_read_count=candidate.already_read_count,
                    )
                    summary['imported'] += 1

                page += 1

        self.stdout.write(self.style.SUCCESS(f"Imported: {summary['imported']}"))
        self.stdout.write(f"Skipped duplicates: {summary['duplicates']}")
        self.stdout.write(f"Skipped invalid: {summary['invalid']}")
        self.stdout.write(f"Categories created: {summary['categories_created']}")
        self.stdout.write(f"Work detail failures: {summary['details_failed']}")

    def _fetch_with_retries(self, query, page, *, page_size, timeout, retries, http_client):
        for attempt in range(1, retries + 1):
            try:
                return fetch_openlibrary_docs(
                    query,
                    page=page,
                    limit=page_size,
                    timeout=timeout,
                    http_client=http_client,
                )
            except Exception as exc:
                if attempt == retries:
                    self.stderr.write(
                        f'Open Library query failed for "{query}" page {page}: {exc}'
                    )
                    return []
                time.sleep(min(0.75 * attempt, 5))
        return []

    def _fetch_work_with_retries(self, work_key, *, timeout, retries, http_client):
        for attempt in range(1, retries + 1):
            try:
                return fetch_openlibrary_work(
                    work_key,
                    timeout=timeout,
                    http_client=http_client,
                )
            except Exception as exc:
                if attempt == retries:
                    self.stderr.write(f'Open Library work detail failed for "{work_key}": {exc}')
                    return {}
                time.sleep(min(0.75 * attempt, 5))
        return {}

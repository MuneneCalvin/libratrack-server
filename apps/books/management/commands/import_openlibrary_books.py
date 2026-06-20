import time

from django.core.management.base import BaseCommand, CommandError

from apps.books.models import Book
from apps.books.openlibrary_importer import (
    TOPIC_QUERIES,
    fetch_openlibrary_docs,
    normalize_openlibrary_doc,
)
from apps.categories.models import Category


class Command(BaseCommand):
    help = 'Import book metadata from Open Library into the local LibraTrack catalog'

    def add_arguments(self, parser):
        parser.add_argument('--limit', type=int, default=500)
        parser.add_argument('--copies', type=int, default=50)

    def handle(self, *args, **options):
        target = options['limit']
        copies = options['copies']

        if target < 1:
            raise CommandError('--limit must be greater than 0')
        if copies < 1:
            raise CommandError('--copies must be greater than 0')

        summary = {
            'imported': 0,
            'duplicates': 0,
            'invalid': 0,
            'categories_created': 0,
        }

        for category_name, query in TOPIC_QUERIES:
            if summary['imported'] >= target:
                break

            category, created = Category.objects.get_or_create(name=category_name)
            if created:
                summary['categories_created'] += 1

            page = 1
            while summary['imported'] < target:
                docs = self._fetch_with_retries(query, page)
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
                    )
                    summary['imported'] += 1

                page += 1

        self.stdout.write(self.style.SUCCESS(f"Imported: {summary['imported']}"))
        self.stdout.write(f"Skipped duplicates: {summary['duplicates']}")
        self.stdout.write(f"Skipped invalid: {summary['invalid']}")
        self.stdout.write(f"Categories created: {summary['categories_created']}")

    def _fetch_with_retries(self, query, page):
        for attempt in range(1, 4):
            try:
                return fetch_openlibrary_docs(query, page=page, limit=100)
            except Exception as exc:
                if attempt == 3:
                    self.stderr.write(
                        f'Open Library query failed for "{query}" page {page}: {exc}'
                    )
                    return []
                time.sleep(0.5 * attempt)
        return []

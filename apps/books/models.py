from django.db import models
from apps.categories.models import Category


class Book(models.Model):
    title = models.CharField(max_length=500)
    author = models.CharField(max_length=500)
    isbn = models.CharField(max_length=20, unique=True)
    category = models.ForeignKey(Category, on_delete=models.PROTECT, related_name='books', db_column='category_id')
    total_copies = models.IntegerField(default=1, db_column='total_copies')
    available_copies = models.IntegerField(default=1, db_column='available_copies')
    publisher = models.CharField(max_length=255, null=True, blank=True)
    published_year = models.IntegerField(null=True, blank=True, db_column='published_year')
    cover_url = models.CharField(max_length=500, null=True, blank=True, db_column='cover_url')
    openlibrary_work_key = models.CharField(max_length=64, null=True, blank=True, db_column='openlibrary_work_key')
    synopsis = models.TextField(null=True, blank=True)
    subjects = models.JSONField(default=list, blank=True)
    language_codes = models.JSONField(default=list, blank=True, db_column='language_codes')
    edition_count = models.PositiveIntegerField(default=0, db_column='edition_count')
    rating_average = models.FloatField(null=True, blank=True, db_column='rating_average')
    rating_count = models.PositiveIntegerField(default=0, db_column='rating_count')
    want_to_read_count = models.PositiveIntegerField(default=0, db_column='want_to_read_count')
    currently_reading_count = models.PositiveIntegerField(default=0, db_column='currently_reading_count')
    already_read_count = models.PositiveIntegerField(default=0, db_column='already_read_count')
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        db_table = 'books'

    def __str__(self):
        return self.title

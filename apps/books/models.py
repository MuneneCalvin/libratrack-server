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
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        db_table = 'books'

    def __str__(self):
        return self.title

from rest_framework import serializers
from apps.books.models import Book


class BookSerializer(serializers.ModelSerializer):
    categoryId = serializers.IntegerField(source='category_id', required=True)
    categoryName = serializers.CharField(source='category.name', read_only=True)
    totalCopies = serializers.IntegerField(source='total_copies', required=True)
    availableCopies = serializers.IntegerField(source='available_copies', required=True)
    publishedYear = serializers.IntegerField(source='published_year', required=False, allow_null=True)
    coverUrl = serializers.CharField(source='cover_url', required=False, allow_null=True, allow_blank=True)

    class Meta:
        model = Book
        fields = [
            'id', 'title', 'author', 'isbn', 'categoryId', 'categoryName',
            'totalCopies', 'availableCopies', 'publisher', 'publishedYear', 'coverUrl',
            'created_at', 'updated_at',
        ]

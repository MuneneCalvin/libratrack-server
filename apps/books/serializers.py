from rest_framework import serializers
from apps.books.models import Book


class BookSerializer(serializers.ModelSerializer):
    categoryId = serializers.IntegerField(source='category_id', required=True)
    categoryName = serializers.CharField(source='category.name', read_only=True)
    totalCopies = serializers.IntegerField(source='total_copies', required=True)
    availableCopies = serializers.IntegerField(source='available_copies', required=True)
    publishedYear = serializers.IntegerField(source='published_year', required=False, allow_null=True)
    coverUrl = serializers.CharField(source='cover_url', required=False, allow_null=True, allow_blank=True)
    openLibraryWorkKey = serializers.CharField(
        source='openlibrary_work_key',
        required=False,
        allow_null=True,
        allow_blank=True,
    )
    languageCodes = serializers.ListField(
        source='language_codes',
        child=serializers.CharField(),
        required=False,
        allow_empty=True,
    )
    editionCount = serializers.IntegerField(source='edition_count', required=False)
    ratingAverage = serializers.FloatField(source='rating_average', required=False, allow_null=True)
    ratingCount = serializers.IntegerField(source='rating_count', required=False)
    wantToReadCount = serializers.IntegerField(source='want_to_read_count', required=False)
    currentlyReadingCount = serializers.IntegerField(source='currently_reading_count', required=False)
    alreadyReadCount = serializers.IntegerField(source='already_read_count', required=False)

    class Meta:
        model = Book
        fields = [
            'id', 'title', 'author', 'isbn', 'categoryId', 'categoryName',
            'totalCopies', 'availableCopies', 'publisher', 'publishedYear', 'coverUrl',
            'openLibraryWorkKey', 'synopsis', 'subjects', 'languageCodes', 'editionCount',
            'ratingAverage', 'ratingCount', 'wantToReadCount', 'currentlyReadingCount',
            'alreadyReadCount',
            'created_at', 'updated_at',
        ]

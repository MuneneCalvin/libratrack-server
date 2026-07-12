from rest_framework import serializers
from apps.categories.models import Category


class CategorySerializer(serializers.ModelSerializer):
    bookCount = serializers.SerializerMethodField()

    class Meta:
        model = Category
        fields = ['id', 'name', 'bookCount']

    def get_bookCount(self, obj):
        annotated_count = getattr(obj, 'book_count', None)
        if annotated_count is not None:
            return annotated_count
        return obj.books.count()

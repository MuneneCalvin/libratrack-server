from rest_framework import generics
from rest_framework.permissions import IsAuthenticated
from django.db.models import Count

from apps.categories.models import Category
from apps.categories.serializers import CategorySerializer
from apps.auth_app.permissions import IsAdmin, IsAdminOrLibrarian
from shared.pagination import StandardPagination


class CategoryListCreateView(generics.ListCreateAPIView):
    serializer_class = CategorySerializer
    pagination_class = StandardPagination

    def get_queryset(self):
        qs = Category.objects.annotate(book_count=Count('books')).order_by('name')
        with_books = self.request.query_params.get('withBooks') or self.request.query_params.get('hasBooks')
        if with_books and with_books.lower() in ('true', '1', 'yes'):
            qs = qs.filter(book_count__gt=0)
        return qs

    def get_permissions(self):
        if self.request.method == 'POST':
            return [IsAdminOrLibrarian()]
        return [IsAuthenticated()]


class CategoryDetailView(generics.RetrieveUpdateDestroyAPIView):
    queryset = Category.objects.annotate(book_count=Count('books')).all()
    serializer_class = CategorySerializer

    def get_permissions(self):
        if self.request.method == 'DELETE':
            return [IsAdmin()]
        if self.request.method in ('PUT', 'PATCH'):
            return [IsAdminOrLibrarian()]
        return [IsAuthenticated()]

from django.db import models as db_models
from rest_framework import generics
from rest_framework.permissions import IsAuthenticated

from apps.books.models import Book
from apps.books.serializers import BookSerializer
from apps.auth_app.permissions import IsAdmin, IsAdminOrLibrarian
from shared.pagination import StandardPagination


class BookListCreateView(generics.ListCreateAPIView):
    serializer_class = BookSerializer
    pagination_class = StandardPagination

    def get_permissions(self):
        if self.request.method == 'POST':
            return [IsAdminOrLibrarian()]
        return [IsAuthenticated()]

    def get_queryset(self):
        qs = Book.objects.select_related('category').order_by('-created_at')
        search = self.request.query_params.get('q') or self.request.query_params.get('search')
        category = self.request.query_params.get('category')
        available = self.request.query_params.get('available')
        if search:
            qs = qs.filter(
                db_models.Q(title__icontains=search)
                | db_models.Q(author__icontains=search)
                | db_models.Q(isbn__icontains=search)
            )
        if category:
            qs = qs.filter(category_id=category)
        if available is not None:
            if available.lower() in ('true', '1', 'yes'):
                qs = qs.filter(available_copies__gt=0)
            elif available.lower() in ('false', '0', 'no'):
                qs = qs.filter(available_copies=0)
        return qs


class BookDetailView(generics.RetrieveUpdateDestroyAPIView):
    queryset = Book.objects.select_related('category').all()
    serializer_class = BookSerializer

    def get_permissions(self):
        if self.request.method == 'DELETE':
            return [IsAdmin()]
        if self.request.method in ('PUT', 'PATCH'):
            return [IsAdminOrLibrarian()]
        return [IsAuthenticated()]

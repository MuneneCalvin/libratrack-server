from datetime import timedelta
from django.db.models import Q
from django.db import transaction as db_tx
from django.utils import timezone
from rest_framework import status
from rest_framework.views import APIView
from rest_framework.response import Response
from rest_framework.generics import get_object_or_404

from apps.transactions.models import BorrowTransaction, TransactionItem
from apps.transactions.serializers import BorrowTransactionSerializer
from apps.members.models import Member
from apps.books.models import Book
from apps.settings_app.models import AppSetting
from apps.auth_app.permissions import IsAdminOrLibrarian
from shared.pagination import StandardPagination


class TransactionListCreateView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        qs = BorrowTransaction.objects.select_related('member').prefetch_related('items__book').order_by('-borrowed_at')
        status_filter = request.query_params.get('status')
        member_id = request.query_params.get('memberId')
        book_id = request.query_params.get('bookId')
        q = request.query_params.get('q')
        if status_filter:
            qs = qs.filter(status=status_filter)
        if member_id:
            qs = qs.filter(member_id=member_id)
        if book_id:
            qs = qs.filter(items__book_id=book_id)
        if q:
            qs = qs.filter(
                Q(member__full_name__icontains=q)
                | Q(items__book__title__icontains=q)
                | Q(items__book__author__icontains=q)
            )
        qs = qs.distinct()
        paginator = StandardPagination()
        page = paginator.paginate_queryset(qs, request)
        return paginator.get_paginated_response(BorrowTransactionSerializer(page, many=True).data)

    def post(self, request):
        member_id = request.data.get('memberId')
        book_ids = request.data.get('bookIds', [])
        if not member_id or not book_ids:
            return Response({'detail': 'memberId and bookIds are required'}, status=status.HTTP_400_BAD_REQUEST)

        max_days = int(AppSetting.objects.get(key='max_borrow_days').value)
        max_books = int(AppSetting.objects.get(key='max_books_per_member').value)
        member = get_object_or_404(Member, id=member_id)

        active_count = TransactionItem.objects.filter(
            transaction__member=member,
            transaction__status__in=['ACTIVE', 'OVERDUE'],
            returned_at__isnull=True,
        ).count()
        if active_count + len(book_ids) > max_books:
            remaining_slots = max(max_books - active_count, 0)
            return Response(
                {
                    'detail': (
                        f'Member already has {active_count} unreturned book(s). '
                        f'The current limit is {max_books}, so they can borrow {remaining_slots} more.'
                    ),
                    'activeBorrowCount': active_count,
                    'maxBooks': max_books,
                    'remainingSlots': remaining_slots,
                },
                status=status.HTTP_400_BAD_REQUEST,
            )

        try:
            with db_tx.atomic():
                due_date = timezone.now() + timedelta(days=max_days)
                tx = BorrowTransaction.objects.create(member=member, due_date=due_date, status='ACTIVE')
                for book_id in book_ids:
                    book = get_object_or_404(Book, id=book_id)
                    if book.available_copies < 1:
                        raise ValueError(f'Book "{book.title}" is not available')
                    TransactionItem.objects.create(transaction=tx, book=book)
                    book.available_copies -= 1
                    book.save()
        except ValueError as e:
            return Response({'detail': str(e)}, status=status.HTTP_400_BAD_REQUEST)

        tx.refresh_from_db()
        return Response(BorrowTransactionSerializer(tx).data, status=status.HTTP_201_CREATED)


class TransactionDetailView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request, pk):
        tx = get_object_or_404(
            BorrowTransaction.objects.select_related('member').prefetch_related('items__book'), id=pk
        )
        return Response(BorrowTransactionSerializer(tx).data)


class TransactionReturnView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def post(self, request, pk):
        tx = get_object_or_404(BorrowTransaction.objects.prefetch_related('items__book'), id=pk)
        unreturned_items = tx.items.filter(returned_at__isnull=True)
        if tx.status == 'RETURNED' or not unreturned_items.exists():
            return Response({'detail': 'Transaction already returned'}, status=status.HTTP_400_BAD_REQUEST)

        item_ids = request.data.get('itemIds')
        if item_ids is not None:
            if not isinstance(item_ids, list) or not item_ids:
                return Response({'detail': 'itemIds must be a non-empty list'}, status=status.HTTP_400_BAD_REQUEST)
            unreturned_items = unreturned_items.filter(id__in=item_ids)
            if unreturned_items.count() != len(set(item_ids)):
                return Response(
                    {'detail': 'One or more selected books are not returnable for this transaction'},
                    status=status.HTTP_400_BAD_REQUEST,
                )

        fine_rate = float(AppSetting.objects.get(key='fine_rate_per_day').value)
        now = timezone.now()

        with db_tx.atomic():
            for item in unreturned_items:
                item.returned_at = now
                item.save()
                item.book.available_copies += 1
                item.book.save()

            if not tx.items.filter(returned_at__isnull=True).exists():
                tx.returned_at = now
                tx.status = 'RETURNED'
                tx.save(update_fields=['returned_at', 'status'])

            if now > tx.due_date:
                from apps.fines.models import Fine
                days_late = max(1, (now.date() - tx.due_date.date()).days)
                amount = fine_rate * days_late
                Fine.objects.get_or_create(
                    transaction=tx,
                    defaults={
                        'member': tx.member,
                        'amount': amount,
                        'reason': f'Book returned {days_late} day(s) late',
                    },
                )

        tx.refresh_from_db()
        return Response(BorrowTransactionSerializer(tx).data)

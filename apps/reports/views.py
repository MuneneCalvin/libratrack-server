from django.db.models import Count, Sum
from rest_framework.views import APIView
from rest_framework.response import Response

from apps.books.models import Book
from apps.categories.models import Category
from apps.members.models import Member
from apps.transactions.models import BorrowTransaction, TransactionItem
from apps.fines.models import Fine
from apps.auth_app.permissions import IsAdminOrLibrarian


class SummaryReportView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        total_books = Book.objects.count()
        total_members = Member.objects.count()
        active_borrows = BorrowTransaction.objects.filter(status='ACTIVE').count()
        overdue_count = BorrowTransaction.objects.filter(status='OVERDUE').count()
        unpaid_fines_total = Fine.objects.filter(is_paid=False, is_waived=False).aggregate(
            total=Sum('amount')
        )['total'] or 0
        return Response({
            'totalBooks': total_books,
            'totalMembers': total_members,
            'activeBorrows': active_borrows,
            'overdueCount': overdue_count,
            'unpaidFinesTotal': float(unpaid_fines_total),
        })


class BorrowingReportView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        active = BorrowTransaction.objects.filter(status='ACTIVE').count()
        overdue = BorrowTransaction.objects.filter(status='OVERDUE').count()
        returned = BorrowTransaction.objects.filter(status='RETURNED').count()
        return Response({'active': active, 'overdue': overdue, 'returned': returned})


class InventoryReportView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        categories = Category.objects.annotate(count=Count('books')).order_by('-count')
        return Response({
            'categories': [{'name': c.name, 'count': c.count} for c in categories]
        })


class FinesReportView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        agg = Fine.objects.aggregate(total=Sum('amount'))
        paid = Fine.objects.filter(is_paid=True).aggregate(total=Sum('amount'))
        unpaid = Fine.objects.filter(is_paid=False, is_waived=False).aggregate(total=Sum('amount'))
        return Response({
            'total': str(agg['total'] or 0),
            'paid': str(paid['total'] or 0),
            'unpaid': str(unpaid['total'] or 0),
        })


class OverdueReportView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        txs = BorrowTransaction.objects.filter(
            status='OVERDUE'
        ).select_related('member').prefetch_related('items__book').order_by('due_date')
        data = []
        for tx in txs:
            data.append({
                'id': tx.id,
                'memberId': tx.member_id,
                'memberName': tx.member.full_name,
                'dueDate': tx.due_date,
                'books': [{'id': item.book.id, 'title': item.book.title} for item in tx.items.all()],
            })
        return Response(data)


class PopularBooksReportView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        books = Book.objects.annotate(
            borrow_count=Count('transaction_items')
        ).order_by('-borrow_count')[:20]
        data = [
            {'id': b.id, 'title': b.title, 'author': b.author, 'borrowCount': b.borrow_count}
            for b in books
        ]
        return Response(data)

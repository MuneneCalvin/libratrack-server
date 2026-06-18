from rest_framework.views import APIView
from rest_framework.response import Response
from rest_framework.permissions import IsAuthenticated
from rest_framework.generics import get_object_or_404

from apps.notifications.models import Notification
from apps.notifications.serializers import NotificationSerializer
from apps.transactions.models import BorrowTransaction
from apps.auth_app.permissions import IsAdminOrLibrarian
from shared.pagination import StandardPagination


class NotificationListView(APIView):
    permission_classes = [IsAuthenticated]

    def get(self, request):
        qs = Notification.objects.filter(user=request.user).order_by('-created_at')
        paginator = StandardPagination()
        page = paginator.paginate_queryset(qs, request)
        return paginator.get_paginated_response(NotificationSerializer(page, many=True).data)


class NotificationReadView(APIView):
    permission_classes = [IsAuthenticated]

    def patch(self, request, pk):
        notification = get_object_or_404(Notification, id=pk, user=request.user)
        notification.is_read = True
        notification.save(update_fields=['is_read'])
        return Response(NotificationSerializer(notification).data)


class NotificationReadAllView(APIView):
    permission_classes = [IsAuthenticated]

    def patch(self, request):
        Notification.objects.filter(user=request.user, is_read=False).update(is_read=True)
        return Response({'message': 'All notifications marked as read'})


class OverdueReminderView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def post(self, request):
        transactions = BorrowTransaction.objects.filter(
            status='OVERDUE'
        ).select_related('member__user').prefetch_related('items__book')
        sent = 0
        for tx in transactions:
            titles = ', '.join(item.book.title for item in tx.items.all()) or 'your borrowed book'
            message = f'Please return {titles}. The due date was {tx.due_date.date()}.'
            already_sent = Notification.objects.filter(
                user=tx.member.user,
                title='Overdue Book Reminder',
                message=message,
                type='OVERDUE',
            ).exists()
            if not already_sent:
                Notification.objects.create(
                    user=tx.member.user,
                    title='Overdue Book Reminder',
                    message=message,
                    type='OVERDUE',
                )
                sent += 1
        return Response({'sent': sent})

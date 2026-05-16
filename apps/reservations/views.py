from datetime import timedelta
from django.utils import timezone
from rest_framework import status
from rest_framework.views import APIView
from rest_framework.response import Response
from rest_framework.permissions import IsAuthenticated
from rest_framework.generics import get_object_or_404

from apps.reservations.models import Reservation
from apps.reservations.serializers import ReservationSerializer
from apps.books.models import Book
from apps.members.models import Member
from apps.settings_app.models import AppSetting
from apps.auth_app.permissions import IsAdminOrLibrarian
from shared.pagination import StandardPagination


class ReservationListCreateView(APIView):
    permission_classes = [IsAuthenticated]

    def get(self, request):
        if request.user.role.name not in ('admin', 'librarian'):
            return Response({'detail': 'Forbidden'}, status=status.HTTP_403_FORBIDDEN)
        qs = Reservation.objects.select_related('member', 'book').order_by('-reserved_at')
        paginator = StandardPagination()
        page = paginator.paginate_queryset(qs, request)
        return paginator.get_paginated_response(ReservationSerializer(page, many=True).data)

    def post(self, request):
        book_id = request.data.get('bookId')
        if not book_id:
            return Response({'detail': 'bookId is required'}, status=status.HTTP_400_BAD_REQUEST)
        book = get_object_or_404(Book, id=book_id)

        expiry_days = int(AppSetting.objects.get(key='reservation_expiry_days').value)

        user_role = request.user.role.name
        if user_role == 'member':
            member = request.user.member
        else:
            member_id = request.data.get('memberId')
            if not member_id:
                return Response({'detail': 'memberId is required for admin/librarian'}, status=status.HTTP_400_BAD_REQUEST)
            member = get_object_or_404(Member, id=member_id)

        reservation = Reservation.objects.create(
            member=member,
            book=book,
            expires_at=timezone.now() + timedelta(days=expiry_days),
            status='PENDING',
        )
        return Response(ReservationSerializer(reservation).data, status=status.HTTP_201_CREATED)


class ReservationDetailView(APIView):
    permission_classes = [IsAuthenticated]

    def get(self, request, pk):
        res = get_object_or_404(Reservation.objects.select_related('member', 'book'), id=pk)
        return Response(ReservationSerializer(res).data)


class ReservationCancelView(APIView):
    permission_classes = [IsAuthenticated]

    def patch(self, request, pk):
        res = get_object_or_404(Reservation.objects.select_related('member', 'book'), id=pk)
        if request.user.role.name == 'member' and res.member.user != request.user:
            return Response({'detail': 'Forbidden'}, status=status.HTTP_403_FORBIDDEN)
        res.status = 'CANCELLED'
        res.save()
        return Response(ReservationSerializer(res).data)


class ReservationFulfillView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def patch(self, request, pk):
        res = get_object_or_404(Reservation.objects.select_related('member', 'book'), id=pk)
        res.status = 'FULFILLED'
        res.save()
        return Response(ReservationSerializer(res).data)

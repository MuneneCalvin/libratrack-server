from rest_framework import generics, status
from rest_framework.views import APIView
from rest_framework.response import Response
from rest_framework.generics import get_object_or_404
from rest_framework.permissions import IsAuthenticated
from rest_framework.exceptions import ValidationError
from django.db import transaction

from apps.members.models import Member
from apps.members.serializers import MemberSerializer, CreateMemberSerializer
from apps.auth_app.models import User
from apps.auth_app.permissions import IsAdmin, IsAdminOrLibrarian, IsSelfOrAdminOrLibrarian
from shared.pagination import StandardPagination


class MemberListCreateView(generics.GenericAPIView):
    permission_classes = [IsAdminOrLibrarian]
    pagination_class = StandardPagination

    def get(self, request):
        from django.db.models import Q
        qs = Member.objects.select_related('user').order_by('-joined_at')
        q = request.query_params.get('q')
        if q:
            qs = qs.filter(Q(full_name__icontains=q) | Q(membership_number__icontains=q))
        page = self.paginate_queryset(qs)
        serializer = MemberSerializer(page, many=True)
        return self.get_paginated_response(serializer.data)

    def post(self, request):
        serializer = CreateMemberSerializer(data=request.data)
        serializer.is_valid(raise_exception=True)
        member = serializer.save()
        return Response(MemberSerializer(member).data, status=status.HTTP_201_CREATED)


class MemberDetailView(generics.RetrieveUpdateDestroyAPIView):
    queryset = Member.objects.select_related('user').all()
    serializer_class = MemberSerializer

    def get_permissions(self):
        if self.request.method == 'DELETE':
            return [IsAdmin()]
        if self.request.method in ('PUT', 'PATCH'):
            return [IsSelfOrAdminOrLibrarian()]
        return [IsSelfOrAdminOrLibrarian()]

    def get_object(self):
        obj = get_object_or_404(Member, pk=self.kwargs['pk'])
        self.check_object_permissions(self.request, obj)
        return obj

    def update(self, request, *args, **kwargs):
        member = self.get_object()
        data = request.data
        member.full_name = data.get('fullName', member.full_name)
        member.phone = data.get('phone', member.phone)
        member.address = data.get('address', member.address)
        if 'email' in data:
            email = str(data.get('email', '')).strip().lower()
            if not email:
                raise ValidationError({'email': 'Email is required.'})
            if User.objects.filter(email__iexact=email).exclude(pk=member.user_id).exists():
                raise ValidationError({'email': 'A user with this email already exists.'})
            member.user.email = email
            member.user.save(update_fields=['email'])
        if request.user.role.name in ('admin', 'librarian'):
            member.membership_number = data.get('membershipNumber', member.membership_number)
            if 'isActive' in data:
                member.user.is_active = bool(data['isActive'])
                member.user.save(update_fields=['is_active'])
        member.save()
        return Response(MemberSerializer(member).data)

    def destroy(self, request, *args, **kwargs):
        member = self.get_object()
        user = member.user

        with transaction.atomic():
            # A permanent member delete should remove dependent account activity first;
            # otherwise PROTECT relationships on fines/reservations/borrows surface as 500s.
            from apps.fines.models import Fine
            from apps.reservations.models import Reservation
            from apps.transactions.models import BorrowTransaction

            Fine.objects.filter(member=member).delete()
            Reservation.objects.filter(member=member).delete()
            BorrowTransaction.objects.filter(member=member).delete()
            member.delete()
            user.delete()

        return Response(status=status.HTTP_204_NO_CONTENT)


class MemberTransactionsView(APIView):
    permission_classes = [IsSelfOrAdminOrLibrarian]

    def get(self, request, pk):
        from apps.transactions.models import BorrowTransaction
        from apps.transactions.serializers import BorrowTransactionSerializer
        member = get_object_or_404(Member, pk=pk)
        self.check_object_permissions(request, member)
        qs = BorrowTransaction.objects.filter(member=member).prefetch_related('items__book').order_by('-borrowed_at')
        status_filter = request.query_params.get('status')
        if status_filter:
            qs = qs.filter(status=status_filter)
        return Response(BorrowTransactionSerializer(qs, many=True).data)


class MemberFinesView(APIView):
    permission_classes = [IsSelfOrAdminOrLibrarian]

    def get(self, request, pk):
        from apps.fines.models import Fine
        from apps.fines.serializers import FineSerializer
        member = get_object_or_404(Member, pk=pk)
        self.check_object_permissions(request, member)
        qs = Fine.objects.filter(member=member).order_by('-created_at')
        is_paid = request.query_params.get('isPaid')
        if is_paid is not None:
            qs = qs.filter(is_paid=is_paid.lower() == 'true')
        return Response(FineSerializer(qs, many=True).data)


class MemberReservationsView(APIView):
    permission_classes = [IsSelfOrAdminOrLibrarian]

    def get(self, request, pk):
        from apps.reservations.models import Reservation
        from apps.reservations.serializers import ReservationSerializer
        member = get_object_or_404(Member, pk=pk)
        self.check_object_permissions(request, member)
        qs = Reservation.objects.filter(member=member).select_related('book').order_by('-reserved_at')
        status_filter = request.query_params.get('status')
        if status_filter:
            qs = qs.filter(status=status_filter)
        return Response(ReservationSerializer(qs, many=True).data)

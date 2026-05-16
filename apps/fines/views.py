from rest_framework.views import APIView
from rest_framework.response import Response
from rest_framework.generics import get_object_or_404

from apps.fines.models import Fine
from apps.fines.serializers import FineSerializer
from apps.auth_app.permissions import IsAdmin, IsAdminOrLibrarian
from shared.pagination import StandardPagination


class FineListView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request):
        qs = Fine.objects.select_related('member').order_by('-created_at')
        member_id = request.query_params.get('memberId')
        is_paid = request.query_params.get('isPaid')
        if member_id:
            qs = qs.filter(member_id=member_id)
        if is_paid is not None:
            qs = qs.filter(is_paid=is_paid.lower() == 'true')
        paginator = StandardPagination()
        page = paginator.paginate_queryset(qs, request)
        return paginator.get_paginated_response(FineSerializer(page, many=True).data)


class FineDetailView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def get(self, request, pk):
        fine = get_object_or_404(Fine.objects.select_related('member'), id=pk)
        return Response(FineSerializer(fine).data)


class FinePayView(APIView):
    permission_classes = [IsAdminOrLibrarian]

    def patch(self, request, pk):
        fine = get_object_or_404(Fine.objects.select_related('member'), id=pk)
        fine.is_paid = True
        fine.save(update_fields=['is_paid'])
        return Response(FineSerializer(fine).data)


class FineWaiveView(APIView):
    permission_classes = [IsAdmin]

    def patch(self, request, pk):
        fine = get_object_or_404(Fine.objects.select_related('member'), id=pk)
        fine.is_waived = True
        fine.waived_by = request.user
        fine.waived_note = request.data.get('waivedNote') or None
        fine.save(update_fields=['is_waived', 'waived_by_id', 'waived_note'])
        return Response(FineSerializer(fine).data)

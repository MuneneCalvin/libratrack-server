from django.urls import path
from apps.members.views import (
    MemberListCreateView, MemberDetailView,
    MemberTransactionsView, MemberFinesView, MemberReservationsView,
)

urlpatterns = [
    path('', MemberListCreateView.as_view(), name='member-list'),
    path('<int:pk>/', MemberDetailView.as_view(), name='member-detail'),
    path('<int:pk>/transactions/', MemberTransactionsView.as_view(), name='member-transactions'),
    path('<int:pk>/fines/', MemberFinesView.as_view(), name='member-fines'),
    path('<int:pk>/reservations/', MemberReservationsView.as_view(), name='member-reservations'),
]

from django.urls import path
from apps.reservations.views import (
    ReservationListCreateView, ReservationDetailView,
    ReservationCancelView, ReservationFulfillView,
)

urlpatterns = [
    path('', ReservationListCreateView.as_view(), name='reservation-list'),
    path('<int:pk>/', ReservationDetailView.as_view(), name='reservation-detail'),
    path('<int:pk>/cancel/', ReservationCancelView.as_view(), name='reservation-cancel'),
    path('<int:pk>/fulfill/', ReservationFulfillView.as_view(), name='reservation-fulfill'),
]

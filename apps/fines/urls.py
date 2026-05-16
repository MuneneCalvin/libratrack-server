from django.urls import path
from apps.fines.views import FineListView, FineDetailView, FinePayView, FineWaiveView

urlpatterns = [
    path('', FineListView.as_view(), name='fine-list'),
    path('<int:pk>/', FineDetailView.as_view(), name='fine-detail'),
    path('<int:pk>/pay/', FinePayView.as_view(), name='fine-pay'),
    path('<int:pk>/waive/', FineWaiveView.as_view(), name='fine-waive'),
]

from django.urls import path
from apps.transactions.views import TransactionListCreateView, TransactionDetailView, TransactionReturnView

urlpatterns = [
    path('', TransactionListCreateView.as_view(), name='transaction-list'),
    path('<int:pk>/', TransactionDetailView.as_view(), name='transaction-detail'),
    path('<int:pk>/return/', TransactionReturnView.as_view(), name='transaction-return'),
]

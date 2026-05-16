from django.urls import path
from apps.reports.views import (
    SummaryReportView, BorrowingReportView, InventoryReportView,
    FinesReportView, OverdueReportView, PopularBooksReportView,
)

urlpatterns = [
    path('summary/', SummaryReportView.as_view(), name='report-summary'),
    path('borrowing/', BorrowingReportView.as_view(), name='report-borrowing'),
    path('inventory/', InventoryReportView.as_view(), name='report-inventory'),
    path('fines/', FinesReportView.as_view(), name='report-fines'),
    path('overdue/', OverdueReportView.as_view(), name='report-overdue'),
    path('popular-books/', PopularBooksReportView.as_view(), name='report-popular'),
]

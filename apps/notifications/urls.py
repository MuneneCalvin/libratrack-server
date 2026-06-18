from django.urls import path
from apps.notifications.views import (
    NotificationListView, NotificationReadView, NotificationReadAllView,
    OverdueReminderView,
)

urlpatterns = [
    path('', NotificationListView.as_view(), name='notification-list'),
    path('read-all/', NotificationReadAllView.as_view(), name='notification-read-all'),
    path('remind/', OverdueReminderView.as_view(), name='notification-remind'),
    path('<int:pk>/read/', NotificationReadView.as_view(), name='notification-read'),
]

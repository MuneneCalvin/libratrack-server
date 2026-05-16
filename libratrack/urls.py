from django.urls import path, include

urlpatterns = [
    path('api/auth/', include('apps.auth_app.urls')),
    path('api/categories/', include('apps.categories.urls')),
    path('api/books/', include('apps.books.urls')),
    path('api/members/', include('apps.members.urls')),
    path('api/transactions/', include('apps.transactions.urls')),
    path('api/reservations/', include('apps.reservations.urls')),
    path('api/fines/', include('apps.fines.urls')),
    path('api/notifications/', include('apps.notifications.urls')),
    path('api/reports/', include('apps.reports.urls')),
    path('api/settings/', include('apps.settings_app.urls')),
]

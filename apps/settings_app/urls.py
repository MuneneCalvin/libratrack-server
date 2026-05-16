from django.urls import path
from apps.settings_app.views import SettingsView

urlpatterns = [
    path('', SettingsView.as_view(), name='settings'),
]

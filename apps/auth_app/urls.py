from django.urls import path
from apps.auth_app.views import LoginView, LogoutView, RefreshView, MeView, ChangePasswordView, SignupView

urlpatterns = [
    path('signup', SignupView.as_view(), name='auth-signup'),
    path('login', LoginView.as_view(), name='auth-login'),
    path('logout', LogoutView.as_view(), name='auth-logout'),
    path('refresh', RefreshView.as_view(), name='auth-refresh'),
    path('me', MeView.as_view(), name='auth-me'),
    path('change-password', ChangePasswordView.as_view(), name='auth-change-password'),
]

from django.utils import timezone
from datetime import timedelta
from rest_framework.views import APIView
from rest_framework.response import Response
from rest_framework.exceptions import AuthenticationFailed
from rest_framework.permissions import IsAuthenticated, AllowAny

from apps.auth_app.models import User, RefreshToken
from apps.auth_app.tokens import generate_access_token, generate_refresh_token, verify_refresh_token
from apps.auth_app.serializers import UserSerializer


class LoginView(APIView):
    permission_classes = [AllowAny]
    authentication_classes = []

    def post(self, request):
        email = request.data.get('email', '').strip()
        password = request.data.get('password', '')
        try:
            user = User.objects.select_related('role').get(email=email, is_active=True)
        except User.DoesNotExist:
            raise AuthenticationFailed('Invalid credentials')
        if not user.check_password(password):
            raise AuthenticationFailed('Invalid credentials')

        access_token = generate_access_token(user)
        refresh_value, refresh_hash = generate_refresh_token()
        RefreshToken.objects.create(
            user=user,
            token_hash=refresh_hash,
            expires_at=timezone.now() + timedelta(days=7),
        )
        response = Response({'accessToken': access_token})
        response.set_cookie('refreshToken', refresh_value, httponly=True, samesite='Lax',
                            max_age=7 * 24 * 60 * 60, secure=False)
        return response


class LogoutView(APIView):
    permission_classes = [IsAuthenticated]

    def post(self, request):
        refresh_value = request.COOKIES.get('refreshToken')
        if refresh_value:
            for token in RefreshToken.objects.filter(user=request.user, revoked_at__isnull=True):
                if verify_refresh_token(refresh_value, token.token_hash):
                    token.revoked_at = timezone.now()
                    token.save()
                    break
        response = Response({'message': 'Logged out'})
        response.delete_cookie('refreshToken')
        return response


class RefreshView(APIView):
    permission_classes = [AllowAny]
    authentication_classes = []

    def post(self, request):
        refresh_value = request.COOKIES.get('refreshToken')
        if not refresh_value:
            raise AuthenticationFailed('No refresh token')
        valid_token = None
        for token in RefreshToken.objects.filter(
            revoked_at__isnull=True, expires_at__gt=timezone.now()
        ).select_related('user__role'):
            if verify_refresh_token(refresh_value, token.token_hash):
                valid_token = token
                break
        if not valid_token:
            raise AuthenticationFailed('Invalid or expired refresh token')
        user = valid_token.user
        valid_token.revoked_at = timezone.now()
        valid_token.save()
        new_value, new_hash = generate_refresh_token()
        RefreshToken.objects.create(user=user, token_hash=new_hash,
                                    expires_at=timezone.now() + timedelta(days=7))
        access_token = generate_access_token(user)
        response = Response({'accessToken': access_token})
        response.set_cookie('refreshToken', new_value, httponly=True, samesite='Lax',
                            max_age=7 * 24 * 60 * 60, secure=False)
        return response


class MeView(APIView):
    permission_classes = [IsAuthenticated]

    def get(self, request):
        return Response(UserSerializer(request.user).data)


class ChangePasswordView(APIView):
    permission_classes = [IsAuthenticated]

    def patch(self, request):
        new_password = request.data.get('password', '').strip()
        if len(new_password) < 8:
            return Response({'message': 'Password must be at least 8 characters'}, status=400)
        request.user.set_password(new_password)
        request.user.must_change_password = False
        request.user.save()
        return Response({'message': 'Password changed successfully'})

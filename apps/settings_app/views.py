from rest_framework.views import APIView
from rest_framework.response import Response
from rest_framework.permissions import IsAuthenticated

from apps.settings_app.models import AppSetting
from apps.auth_app.permissions import IsAdmin


class SettingsView(APIView):
    def get_permissions(self):
        if self.request.method == 'PUT':
            return [IsAdmin()]
        return [IsAuthenticated()]

    def get(self, request):
        settings = AppSetting.objects.all()
        data = {s.key: s.value for s in settings}
        return Response(data)

    def put(self, request):
        for key, value in request.data.items():
            AppSetting.objects.filter(key=key).update(value=str(value))
        settings = AppSetting.objects.all()
        data = {s.key: s.value for s in settings}
        return Response(data)

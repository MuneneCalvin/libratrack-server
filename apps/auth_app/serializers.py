from rest_framework import serializers
from apps.auth_app.models import User


class UserSerializer(serializers.ModelSerializer):
    role = serializers.CharField(source='role.name', read_only=True)
    memberId = serializers.IntegerField(source='member_id', read_only=True)
    mustChangePassword = serializers.BooleanField(source='must_change_password', read_only=True)

    class Meta:
        model = User
        fields = ['id', 'email', 'role', 'memberId', 'mustChangePassword']

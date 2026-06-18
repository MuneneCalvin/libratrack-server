import uuid

from rest_framework import serializers
from apps.auth_app.models import Role, User
from apps.members.models import Member


class UserSerializer(serializers.ModelSerializer):
    role = serializers.CharField(source='role.name', read_only=True)
    memberId = serializers.IntegerField(source='member_id', read_only=True)
    mustChangePassword = serializers.BooleanField(source='must_change_password', read_only=True)

    class Meta:
        model = User
        fields = ['id', 'email', 'role', 'memberId', 'mustChangePassword']


class PublicSignupSerializer(serializers.Serializer):
    email = serializers.EmailField()
    password = serializers.CharField(min_length=8, write_only=True)
    fullName = serializers.CharField()
    phone = serializers.CharField(required=False, allow_blank=True)
    address = serializers.CharField(required=False, allow_blank=True)

    def validate_email(self, value):
        email = value.strip().lower()
        if User.objects.filter(email__iexact=email).exists():
            raise serializers.ValidationError('A user with this email already exists.')
        return email

    def create(self, validated_data):
        member_role = Role.objects.get(name='member')
        user = User.objects.create_user(
            email=validated_data['email'],
            role=member_role,
            password=validated_data['password'],
            must_change_password=False,
        )
        membership_number = 'MEM-' + uuid.uuid4().hex[:6].upper()
        while Member.objects.filter(membership_number=membership_number).exists():
            membership_number = 'MEM-' + uuid.uuid4().hex[:6].upper()
        return Member.objects.create(
            user=user,
            full_name=validated_data['fullName'],
            membership_number=membership_number,
            phone=validated_data.get('phone', ''),
            address=validated_data.get('address', ''),
        )

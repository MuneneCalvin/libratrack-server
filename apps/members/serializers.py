import uuid
from rest_framework import serializers
from apps.members.models import Member
from apps.auth_app.models import User, Role


class MemberSerializer(serializers.ModelSerializer):
    email = serializers.EmailField(source='user.email', read_only=True)
    fullName = serializers.CharField(source='full_name')
    membershipNumber = serializers.CharField(source='membership_number')
    joinedAt = serializers.DateTimeField(source='joined_at', read_only=True)
    isActive = serializers.BooleanField(source='user.is_active', read_only=True)

    class Meta:
        model = Member
        fields = ['id', 'email', 'fullName', 'phone', 'address', 'membershipNumber', 'joinedAt', 'isActive']


class CreateMemberSerializer(serializers.Serializer):
    email = serializers.EmailField()
    password = serializers.CharField(min_length=6)
    fullName = serializers.CharField()
    phone = serializers.CharField(required=False, allow_blank=True)
    address = serializers.CharField(required=False, allow_blank=True)

    def create(self, validated_data):
        member_role = Role.objects.get(name='member')
        user = User(email=validated_data['email'], role=member_role, must_change_password=True)
        user.set_password(validated_data['password'])
        user.save()
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

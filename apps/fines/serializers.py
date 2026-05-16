from rest_framework import serializers
from apps.fines.models import Fine


class FineSerializer(serializers.ModelSerializer):
    memberId = serializers.IntegerField(source='member_id', read_only=True)
    memberName = serializers.CharField(source='member.full_name', read_only=True)
    transactionId = serializers.IntegerField(source='transaction_id', read_only=True)
    isPaid = serializers.BooleanField(source='is_paid', read_only=True)
    isWaived = serializers.BooleanField(source='is_waived', read_only=True)
    waivedNote = serializers.CharField(source='waived_note', read_only=True)
    createdAt = serializers.DateTimeField(source='created_at', read_only=True)

    class Meta:
        model = Fine
        fields = ['id', 'memberId', 'memberName', 'transactionId', 'amount', 'reason',
                  'isPaid', 'isWaived', 'waivedNote', 'createdAt']

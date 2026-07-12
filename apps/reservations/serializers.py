from rest_framework import serializers
from apps.reservations.models import Reservation


class ReservationSerializer(serializers.ModelSerializer):
    memberId = serializers.IntegerField(source='member_id', read_only=True)
    memberName = serializers.CharField(source='member.full_name', read_only=True)
    bookId = serializers.IntegerField(source='book_id', read_only=True)
    bookTitle = serializers.CharField(source='book.title', read_only=True)
    bookAuthor = serializers.CharField(source='book.author', read_only=True)
    bookCoverUrl = serializers.CharField(source='book.cover_url', read_only=True, allow_null=True)
    reservedAt = serializers.DateTimeField(source='reserved_at', read_only=True)
    expiresAt = serializers.DateTimeField(source='expires_at', read_only=True)

    class Meta:
        model = Reservation
        fields = [
            'id', 'memberId', 'memberName', 'bookId', 'bookTitle', 'bookAuthor',
            'bookCoverUrl', 'reservedAt', 'expiresAt', 'status',
        ]

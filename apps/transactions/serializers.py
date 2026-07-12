from rest_framework import serializers
from apps.transactions.models import BorrowTransaction, TransactionItem
from apps.books.serializers import BookSerializer


class TransactionItemSerializer(serializers.ModelSerializer):
    book = BookSerializer(read_only=True)
    returnedAt = serializers.DateTimeField(source='returned_at', read_only=True)

    class Meta:
        model = TransactionItem
        fields = ['id', 'book', 'returnedAt']


class BorrowTransactionSerializer(serializers.ModelSerializer):
    memberId = serializers.IntegerField(source='member_id', read_only=True)
    memberName = serializers.CharField(source='member.full_name', read_only=True)
    borrowedAt = serializers.DateTimeField(source='borrowed_at', read_only=True)
    dueDate = serializers.DateTimeField(source='due_date', read_only=True)
    returnedAt = serializers.DateTimeField(source='returned_at', read_only=True)
    items = TransactionItemSerializer(many=True, read_only=True)

    class Meta:
        model = BorrowTransaction
        fields = ['id', 'memberId', 'memberName', 'borrowedAt', 'dueDate', 'returnedAt', 'status', 'items']

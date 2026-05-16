from django.db import models
from apps.members.models import Member
from apps.books.models import Book


class TransactionStatus(models.TextChoices):
    ACTIVE = 'ACTIVE', 'Active'
    RETURNED = 'RETURNED', 'Returned'
    OVERDUE = 'OVERDUE', 'Overdue'


class BorrowTransaction(models.Model):
    member = models.ForeignKey(Member, on_delete=models.PROTECT, related_name='transactions', db_column='member_id')
    borrowed_at = models.DateTimeField(auto_now_add=True, db_column='borrowed_at')
    due_date = models.DateTimeField(db_column='due_date')
    returned_at = models.DateTimeField(null=True, blank=True, db_column='returned_at')
    status = models.CharField(max_length=20, choices=TransactionStatus.choices, default=TransactionStatus.ACTIVE)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        db_table = 'borrow_transactions'

    def __str__(self):
        return f'Transaction {self.id} - {self.member}'


class TransactionItem(models.Model):
    transaction = models.ForeignKey(BorrowTransaction, on_delete=models.CASCADE, related_name='items', db_column='transaction_id')
    book = models.ForeignKey(Book, on_delete=models.PROTECT, related_name='transaction_items', db_column='book_id')
    returned_at = models.DateTimeField(null=True, blank=True, db_column='returned_at')

    class Meta:
        db_table = 'transaction_items'

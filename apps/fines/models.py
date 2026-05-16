from django.db import models
from django.conf import settings
from apps.members.models import Member
from apps.transactions.models import BorrowTransaction


class Fine(models.Model):
    transaction = models.OneToOneField(BorrowTransaction, on_delete=models.PROTECT, related_name='fine', db_column='transaction_id')
    member = models.ForeignKey(Member, on_delete=models.PROTECT, related_name='fines', db_column='member_id')
    amount = models.DecimalField(max_digits=10, decimal_places=2)
    reason = models.CharField(max_length=500)
    is_paid = models.BooleanField(default=False, db_column='is_paid')
    is_waived = models.BooleanField(default=False, db_column='is_waived')
    waived_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, blank=True,
        on_delete=models.SET_NULL, related_name='waived_fines', db_column='waived_by'
    )
    waived_note = models.CharField(max_length=500, null=True, blank=True, db_column='waived_note')
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        db_table = 'fines'

    def __str__(self):
        return f'Fine {self.id} - {self.member} - {self.amount}'

from django.db import models
from apps.members.models import Member
from apps.books.models import Book


class ReservationStatus(models.TextChoices):
    PENDING = 'PENDING', 'Pending'
    FULFILLED = 'FULFILLED', 'Fulfilled'
    CANCELLED = 'CANCELLED', 'Cancelled'
    EXPIRED = 'EXPIRED', 'Expired'


class Reservation(models.Model):
    member = models.ForeignKey(Member, on_delete=models.PROTECT, related_name='reservations', db_column='member_id')
    book = models.ForeignKey(Book, on_delete=models.PROTECT, related_name='reservations', db_column='book_id')
    reserved_at = models.DateTimeField(auto_now_add=True, db_column='reserved_at')
    expires_at = models.DateTimeField(db_column='expires_at')
    status = models.CharField(max_length=20, choices=ReservationStatus.choices, default=ReservationStatus.PENDING)

    class Meta:
        db_table = 'reservations'

    def __str__(self):
        return f'Reservation {self.id} - {self.member} for {self.book}'

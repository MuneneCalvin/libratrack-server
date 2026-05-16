from django.core.management.base import BaseCommand
from django.utils import timezone
from apps.transactions.models import BorrowTransaction


class Command(BaseCommand):
    help = 'Mark all ACTIVE transactions past their due date as OVERDUE'

    def handle(self, *args, **options):
        updated = BorrowTransaction.objects.filter(
            status='ACTIVE',
            due_date__lt=timezone.now(),
        ).update(status='OVERDUE')
        self.stdout.write(self.style.SUCCESS(f'Marked {updated} transaction(s) as OVERDUE'))

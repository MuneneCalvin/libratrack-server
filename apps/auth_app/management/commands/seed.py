from django.core.management.base import BaseCommand
from django.utils import timezone
from datetime import timedelta
from apps.auth_app.models import Role, User
from apps.categories.models import Category
from apps.books.models import Book
from apps.members.models import Member
from apps.transactions.models import BorrowTransaction, TransactionItem
from apps.reservations.models import Reservation
from apps.fines.models import Fine
from apps.notifications.models import Notification
from apps.settings_app.models import AppSetting


class Command(BaseCommand):
    help = 'Seed the database with initial test data'

    def handle(self, *args, **options):
        # Roles
        admin_role, _ = Role.objects.get_or_create(name='admin')
        librarian_role, _ = Role.objects.get_or_create(name='librarian')
        member_role, _ = Role.objects.get_or_create(name='member')
        self.stdout.write('Roles created')

        # App Settings
        for key, value in [
            ('fine_rate_per_day', '5'),
            ('max_borrow_days', '14'),
            ('max_books_per_member', '5'),
            ('reservation_expiry_days', '3'),
        ]:
            AppSetting.objects.get_or_create(key=key, defaults={'value': value})
        self.stdout.write('Settings created')

        # Categories
        categories = {}
        for name in ['Fiction', 'Non-Fiction', 'Science', 'History', 'Technology', 'Arts']:
            categories[name], _ = Category.objects.get_or_create(name=name)
        self.stdout.write('Categories created')

        # Users
        def get_or_create_user(email, password, role):
            if not User.objects.filter(email=email).exists():
                u = User(email=email, role=role)
                u.set_password(password)
                u.save()
                return u
            return User.objects.get(email=email)

        admin_user = get_or_create_user('admin@libratrack.com', 'Admin@1234', admin_role)
        librarian_user = get_or_create_user('librarian@libratrack.com', 'Librarian@1234', librarian_role)
        member1_user = get_or_create_user('alice@libratrack.com', 'Member@1234', member_role)
        member2_user = get_or_create_user('bob@libratrack.com', 'Member@1234', member_role)
        self.stdout.write('Users created')

        # Members
        alice, _ = Member.objects.get_or_create(
            user=member1_user,
            defaults={'full_name': 'Alice Johnson', 'phone': '555-0101', 'membership_number': 'LIB-0001'},
        )
        bob, _ = Member.objects.get_or_create(
            user=member2_user,
            defaults={'full_name': 'Bob Smith', 'phone': '555-0102', 'membership_number': 'LIB-0002'},
        )
        self.stdout.write('Members created')

        # Books
        book_data = [
            ('Clean Code', 'Robert C. Martin', '978-0132350884', 'Technology', 3, 2, 'Prentice Hall', 2008),
            ('The Pragmatic Programmer', 'David Thomas & Andrew Hunt', '978-0135957059', 'Technology', 2, 2, 'Addison-Wesley', 2019),
            ('Designing Data-Intensive Applications', 'Martin Kleppmann', '978-1449373320', 'Technology', 2, 1, "O'Reilly", 2017),
            ('The Great Gatsby', 'F. Scott Fitzgerald', '978-0743273565', 'Fiction', 4, 3, 'Scribner', 1925),
            ('To Kill a Mockingbird', 'Harper Lee', '978-0061935466', 'Fiction', 3, 3, 'Harper Perennial', 1960),
            ('Sapiens', 'Yuval Noah Harari', '978-0062316097', 'History', 3, 2, 'Harper', 2015),
            ('A Brief History of Time', 'Stephen Hawking', '978-0553380163', 'Science', 2, 2, 'Bantam', 1988),
            ('The Origin of Species', 'Charles Darwin', '978-0140432053', 'Science', 2, 2, 'Penguin Classics', 1859),
            ('Thinking, Fast and Slow', 'Daniel Kahneman', '978-0374533557', 'Non-Fiction', 3, 3, 'Farrar, Straus and Giroux', 2011),
            ('The Story of Art', 'E.H. Gombrich', '978-0714832470', 'Arts', 2, 2, 'Phaidon', 1950),
        ]
        books = {}
        for title, author, isbn, cat, total, avail, pub, year in book_data:
            book, _ = Book.objects.get_or_create(
                isbn=isbn,
                defaults={
                    'title': title, 'author': author, 'category': categories[cat],
                    'total_copies': total, 'available_copies': avail,
                    'publisher': pub, 'published_year': year,
                },
            )
            books[isbn] = book
        self.stdout.write('Books created')

        # Active borrow: Alice has Clean Code
        clean_code = books['978-0132350884']
        if not BorrowTransaction.objects.filter(member=alice, status='ACTIVE').exists():
            tx = BorrowTransaction.objects.create(
                member=alice,
                due_date=timezone.now() + timedelta(days=5),
                status='ACTIVE',
            )
            TransactionItem.objects.create(transaction=tx, book=clean_code)
            clean_code.available_copies -= 1
            clean_code.save()

        # Overdue return: Bob returned Sapiens late
        sapiens = books['978-0062316097']
        if not BorrowTransaction.objects.filter(member=bob, status='RETURNED').exists():
            overdue_tx = BorrowTransaction.objects.create(
                member=bob,
                borrowed_at=timezone.now() - timedelta(days=34),
                due_date=timezone.now() - timedelta(days=20),
                returned_at=timezone.now() - timedelta(days=2),
                status='RETURNED',
            )
            TransactionItem.objects.create(transaction=overdue_tx, book=sapiens,
                                           returned_at=timezone.now() - timedelta(days=2))
            Fine.objects.get_or_create(
                transaction=overdue_tx,
                defaults={'member': bob, 'amount': 90, 'reason': 'Book returned 18 days late'},
            )

        # Reservation: Bob has DDIA reserved
        ddia = books['978-1449373320']
        if not Reservation.objects.filter(member=bob, book=ddia, status='PENDING').exists():
            Reservation.objects.create(
                member=bob, book=ddia,
                expires_at=timezone.now() + timedelta(days=3),
                status='PENDING',
            )

        # Notifications
        for user, title, message, ntype in [
            (member1_user, 'Borrow Confirmed', 'You have borrowed "Clean Code". Due in 5 days.', 'BORROW'),
            (member2_user, 'Overdue Fine', 'A fine of ₹90 has been applied for late return of "Sapiens".', 'FINE'),
            (member2_user, 'Reservation Confirmed', 'Your reservation for "Designing Data-Intensive Applications" is active.', 'RESERVATION'),
        ]:
            Notification.objects.get_or_create(
                user=user, title=title,
                defaults={'message': message, 'type': ntype},
            )

        self.stdout.write(self.style.SUCCESS('\nSeed complete!'))
        self.stdout.write('\nTest credentials:')
        self.stdout.write('  Admin:     admin@libratrack.com     / Admin@1234')
        self.stdout.write('  Librarian: librarian@libratrack.com / Librarian@1234')
        self.stdout.write('  Member 1:  alice@libratrack.com     / Member@1234')
        self.stdout.write('  Member 2:  bob@libratrack.com       / Member@1234')

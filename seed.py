"""
Book Tracking System — Database Seed Script
============================================
Populates the database with roles, staff accounts, member accounts,
categories, books, transactions, fines, reservations, and library settings.

Usage:
    python seed.py

Safe to re-run — uses get_or_create throughout so existing records are
not duplicated. If you want a clean slate, wipe the database first:
    python manage.py flush --no-input
    python seed.py
"""

import os
import sys
import django
from decimal import Decimal
from datetime import timedelta

os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'libratrack.settings.dev')
django.setup()

from django.utils import timezone
from apps.auth_app.models import Role, User
from apps.members.models import Member
from apps.categories.models import Category
from apps.books.models import Book
from apps.transactions.models import BorrowTransaction, TransactionItem, TransactionStatus
from apps.fines.models import Fine
from apps.reservations.models import Reservation, ReservationStatus
from apps.settings_app.models import AppSetting

now = timezone.now()

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def section(title):
    print(f"\n{'─' * 50}")
    print(f"  {title}")
    print(f"{'─' * 50}")


def _set_date(model_class, pk, field, value):
    """Bypass auto_now / auto_now_add to set a custom datetime."""
    model_class.objects.filter(pk=pk).update(**{field: value})


# ---------------------------------------------------------------------------
# 1. Roles
# ---------------------------------------------------------------------------
section("Roles")

admin_role,     _ = Role.objects.get_or_create(name='admin')
librarian_role, _ = Role.objects.get_or_create(name='librarian')
member_role,    _ = Role.objects.get_or_create(name='member')
print("  ✓ admin, librarian, member")

# ---------------------------------------------------------------------------
# 2. Staff accounts
# ---------------------------------------------------------------------------
section("Staff accounts")

staff = [
    ('admin@booktracking.com',     'Admin@1234',     admin_role),
    ('librarian@booktracking.com', 'Librarian@1234', librarian_role),
]
for email, password, role in staff:
    if not User.objects.filter(email=email).exists():
        User.objects.create_user(email=email, role=role, password=password)
        print(f"  ✓ Created {email}")
    else:
        print(f"  · Exists  {email}")

# ---------------------------------------------------------------------------
# 3. Categories
# ---------------------------------------------------------------------------
section("Categories")

cat_names = [
    'Fiction', 'Non-Fiction', 'Science & Technology', 'History',
    'Biography', 'Self-Help', 'Mystery & Thriller', 'Fantasy',
    'Science Fiction', 'Children', 'Reference', 'Arts & Literature',
]
categories = {}
for name in cat_names:
    cat, created = Category.objects.get_or_create(name=name)
    categories[name] = cat
    if created:
        print(f"  ✓ {name}")
print(f"  Total categories: {len(categories)}")

# ---------------------------------------------------------------------------
# 4. Books
# ---------------------------------------------------------------------------
section("Books")

# (title, author, isbn, category, total_copies, available_copies, publisher, year)
books_data = [
    # Fiction
    ('To Kill a Mockingbird',               'Harper Lee',              '9780061935466', 'Fiction',             5, 4, 'Grand Central Publishing', 2002),
    ('1984',                                'George Orwell',           '9780451524935', 'Fiction',             4, 2, 'Signet Classic',           1961),
    ('Pride and Prejudice',                 'Jane Austen',             '9780141439518', 'Fiction',             3, 3, 'Penguin Classics',         2002),
    ('The Great Gatsby',                    'F. Scott Fitzgerald',     '9780743273565', 'Fiction',             4, 1, 'Scribner',                 2004),
    ('Of Mice and Men',                     'John Steinbeck',          '9780140177398', 'Fiction',             3, 3, 'Penguin Books',            1994),
    # Mystery & Thriller
    ('Gone Girl',                           'Gillian Flynn',           '9780307588371', 'Mystery & Thriller',  5, 3, 'Broadway Books',           2013),
    ('The Girl with the Dragon Tattoo',     'Stieg Larsson',           '9780307949486', 'Mystery & Thriller',  4, 2, 'Vintage Crime',            2011),
    ('Big Little Lies',                     'Liane Moriarty',          '9780425274866', 'Mystery & Thriller',  3, 3, 'Berkley',                  2015),
    ('In the Woods',                        'Tana French',             '9780143113492', 'Mystery & Thriller',  3, 2, 'Penguin Books',            2008),
    # Science & Technology
    ('A Brief History of Time',             'Stephen Hawking',         '9780553380163', 'Science & Technology',4, 3, 'Bantam',                   1998),
    ('The Pragmatic Programmer',            'Andrew Hunt & David Thomas','9780135957059','Science & Technology',3, 2, 'Addison-Wesley',           2019),
    ('Clean Code',                          'Robert C. Martin',        '9780132350884', 'Science & Technology',4, 4, 'Prentice Hall',            2008),
    ('Sapiens',                             'Yuval Noah Harari',       '9780062316097', 'Science & Technology',5, 3, 'Harper',                   2015),
    # History
    ('The Guns of August',                  'Barbara Tuchman',         '9780345476098', 'History',             3, 3, 'Ballantine Books',         2004),
    ('Team of Rivals',                      'Doris Kearns Goodwin',    '9780743270755', 'History',             4, 2, 'Simon & Schuster',         2006),
    ('SPQR: A History of Ancient Rome',     'Mary Beard',              '9781631492228', 'History',             3, 3, 'Liveright',                2016),
    # Biography
    ('Steve Jobs',                          'Walter Isaacson',         '9781451648539', 'Biography',           5, 4, 'Simon & Schuster',         2011),
    ('Educated',                            'Tara Westover',           '9780399590504', 'Biography',           4, 2, 'Random House',             2018),
    ('The Diary of a Young Girl',           'Anne Frank',              '9780553296983', 'Biography',           5, 5, 'Bantam',                   1993),
    # Self-Help
    ('Atomic Habits',                       'James Clear',             '9780735211292', 'Self-Help',           6, 4, 'Avery',                    2018),
    ('The 7 Habits of Highly Effective People','Stephen Covey',        '9781982137274', 'Self-Help',           4, 3, 'Simon & Schuster',         2020),
    ('Thinking, Fast and Slow',             'Daniel Kahneman',         '9780374533557', 'Self-Help',           4, 4, 'Farrar Straus Giroux',     2011),
    # Fantasy
    ('The Hobbit',                          'J.R.R. Tolkien',          '9780547928227', 'Fantasy',             5, 3, 'Houghton Mifflin',         2012),
    ("Harry Potter and the Sorcerer's Stone",'J.K. Rowling',           '9780590353427', 'Fantasy',             6, 3, 'Scholastic',               1998),
    ('The Name of the Wind',                'Patrick Rothfuss',        '9780756404741', 'Fantasy',             4, 4, 'DAW Books',                2007),
    # Science Fiction
    ('Dune',                                'Frank Herbert',           '9780441013593', 'Science Fiction',     5, 4, 'Ace',                      2019),
    ("The Hitchhiker's Guide to the Galaxy",'Douglas Adams',           '9780345391803', 'Science Fiction',     4, 4, 'Del Rey',                  1995),
    ("Ender's Game",                        'Orson Scott Card',        '9780812550702', 'Science Fiction',     4, 3, 'Tor Teen',                 1994),
    # Children
    ("Charlotte's Web",                     'E.B. White',              '9780061124952', 'Children',            5, 5, 'HarperCollins',            2012),
    ('Where the Wild Things Are',           'Maurice Sendak',          '9780060254926', 'Children',            4, 4, 'HarperCollins',            1988),
    # Arts & Literature
    ('On Writing',                          'Stephen King',            '9781439156810', 'Arts & Literature',   3, 3, 'Scribner',                 2010),
    ('Bird by Bird',                        'Anne Lamott',             '9780385480017', 'Arts & Literature',   3, 3, 'Anchor',                   1995),
]

books = []
created_count = 0
for title, author, isbn, cat_name, total, available, publisher, year in books_data:
    book, created = Book.objects.get_or_create(
        isbn=isbn,
        defaults=dict(
            title=title, author=author,
            category=categories[cat_name],
            total_copies=total, available_copies=available,
            publisher=publisher, published_year=year,
        ),
    )
    books.append(book)
    if created:
        created_count += 1
print(f"  ✓ {created_count} created  ·  {len(books)} total books")

# ---------------------------------------------------------------------------
# 5. Members
# ---------------------------------------------------------------------------
section("Member accounts")

# (email, full_name, membership_number, phone, address)
members_data = [
    ('alice@example.com',  'Alice Wanjiku',   'MEM-001', '+254 712 000 001', 'Westlands, Nairobi'),
    ('bob@example.com',    'Bob Otieno',      'MEM-002', '+254 722 000 002', 'Kilimani, Nairobi'),
    ('carol@example.com',  'Carol Achieng',   'MEM-003', '+254 733 000 003', 'Lavington, Nairobi'),
    ('david@example.com',  'David Kamau',     'MEM-004', '+254 700 000 004', 'Kasarani, Nairobi'),
    ('eva@example.com',    'Eva Njeri',       'MEM-005', '+254 710 000 005', 'Parklands, Nairobi'),
    ('frank@example.com',  'Frank Mwangi',    'MEM-006', '+254 720 000 006', 'Embakasi, Nairobi'),
    ('grace@example.com',  'Grace Auma',      'MEM-007', '+254 730 000 007', 'Ruaka, Nairobi'),
    ('henry@example.com',  'Henry Kipchoge',  'MEM-008', '+254 740 000 008', 'Thika Road, Nairobi'),
    ('iris@example.com',   'Iris Mutua',      'MEM-009', '+254 750 000 009', 'Karen, Nairobi'),
    ('james@example.com',  'James Odhiambo',  'MEM-010', '+254 760 000 010', 'Syokimau, Nairobi'),
    ('karen@example.com',  'Karen Chebet',    'MEM-011', '+254 770 000 011', 'Rongai, Nairobi'),
    ('liam@example.com',   'Liam Njoroge',    'MEM-012', '+254 780 000 012', 'Ruiru, Kiambu'),
]

member_objects = []
created_count = 0
for email, full_name, mem_num, phone, address in members_data:
    if not User.objects.filter(email=email).exists():
        u = User.objects.create_user(email=email, role=member_role, password='Member@1234')
        created_count += 1
    else:
        u = User.objects.get(email=email)
    member, _ = Member.objects.get_or_create(
        user=u,
        defaults=dict(full_name=full_name, membership_number=mem_num, phone=phone, address=address),
    )
    member_objects.append(member)

print(f"  ✓ {created_count} created  ·  {len(member_objects)} total members")
print(f"  Password for all members: Member@1234")

# ---------------------------------------------------------------------------
# 6. Library settings
# ---------------------------------------------------------------------------
section("Library settings")

default_settings = {
    'fine_rate_per_day':      '50',   # KES 50 per day overdue
    'max_borrow_days':        '14',   # 2-week loan period
    'max_books_per_member':   '5',    # max concurrent borrows
    'reservation_expiry_days':'7',    # reservations expire after 7 days
}
for key, value in default_settings.items():
    setting, created = AppSetting.objects.get_or_create(key=key, defaults={'value': value})
    status = 'created' if created else 'exists'
    print(f"  {'✓' if created else '·'} {key} = {setting.value}  ({status})")

FINE_RATE = Decimal(AppSetting.objects.get(key='fine_rate_per_day').value)

# ---------------------------------------------------------------------------
# 7. Transactions  (skipped on re-run — no natural unique key)
# ---------------------------------------------------------------------------
section("Transactions")

ACTIVE   = TransactionStatus.ACTIVE
OVERDUE  = TransactionStatus.OVERDUE
RETURNED = TransactionStatus.RETURNED


def make_transaction(member, book, days_ago, loan_days, status, returned_days_ago=None):
    """
    Create a borrow transaction with realistic historical dates.
    borrowed_at has auto_now_add=True so we bypass it with a queryset update.
    """
    borrowed_at = now - timedelta(days=days_ago)
    due_date    = borrowed_at + timedelta(days=loan_days)
    returned_at = (now - timedelta(days=returned_days_ago)) if returned_days_ago is not None else None

    tx = BorrowTransaction.objects.create(
        member=member, due_date=due_date, returned_at=returned_at, status=status,
    )
    BorrowTransaction.objects.filter(pk=tx.pk).update(borrowed_at=borrowed_at)
    TransactionItem.objects.create(transaction=tx, book=book, returned_at=returned_at)
    return tx


if BorrowTransaction.objects.exists():
    print(f"  · Skipping — {BorrowTransaction.objects.count()} transactions already exist (re-run after flush to reset)")
    t8 = t9 = t10 = t11 = t14 = t15 = t18 = None
else:
    # Active borrows (still within loan period)
    t1  = make_transaction(member_objects[0],  books[0],   5,  14, ACTIVE)    # Alice  — To Kill a Mockingbird
    t2  = make_transaction(member_objects[1],  books[3],   3,  14, ACTIVE)    # Bob    — The Great Gatsby
    t3  = make_transaction(member_objects[2],  books[9],   7,  14, ACTIVE)    # Carol  — A Brief History of Time
    t4  = make_transaction(member_objects[3],  books[12],  2,  14, ACTIVE)    # David  — Sapiens
    t5  = make_transaction(member_objects[4],  books[16],  1,  14, ACTIVE)    # Eva    — Steve Jobs
    t6  = make_transaction(member_objects[5],  books[20],  6,  14, ACTIVE)    # Frank  — Thinking, Fast and Slow
    t7  = make_transaction(member_objects[6],  books[22],  4,  14, ACTIVE)    # Grace  — The Hobbit

    # Overdue borrows (past due date, not yet returned)
    t8  = make_transaction(member_objects[7],  books[1],   20, 14, OVERDUE)   # Henry  — 1984             (6d overdue)
    t9  = make_transaction(member_objects[8],  books[6],   25, 14, OVERDUE)   # Iris   — Dragon Tattoo   (11d overdue)
    t10 = make_transaction(member_objects[9],  books[10],  30, 14, OVERDUE)   # James  — Pragmatic Prog  (16d overdue)
    t11 = make_transaction(member_objects[0],  books[5],   18, 14, OVERDUE)   # Alice  — Gone Girl        (4d overdue)

    # Returned borrows (historical records)
    t12 = make_transaction(member_objects[1],  books[2],   40, 14, RETURNED, returned_days_ago=27)
    t13 = make_transaction(member_objects[2],  books[7],   35, 14, RETURNED, returned_days_ago=22)
    t14 = make_transaction(member_objects[3],  books[11],  50, 14, RETURNED, returned_days_ago=37)
    t15 = make_transaction(member_objects[4],  books[14],  60, 14, RETURNED, returned_days_ago=47)
    t16 = make_transaction(member_objects[5],  books[18],  45, 14, RETURNED, returned_days_ago=32)
    t17 = make_transaction(member_objects[6],  books[23],  55, 14, RETURNED, returned_days_ago=42)
    t18 = make_transaction(member_objects[7],  books[26],  70, 14, RETURNED, returned_days_ago=57)
    t19 = make_transaction(member_objects[8],  books[29],  80, 14, RETURNED, returned_days_ago=67)
    t20 = make_transaction(member_objects[9],  books[0],   90, 14, RETURNED, returned_days_ago=77)
    t21 = make_transaction(member_objects[10], books[4],   65, 14, RETURNED, returned_days_ago=52)
    t22 = make_transaction(member_objects[11], books[19],  75, 14, RETURNED, returned_days_ago=62)

    print(f"  ✓ {BorrowTransaction.objects.count()} total transactions")
    print(f"    Active: 7  ·  Overdue: 4  ·  Returned: 11")

# ---------------------------------------------------------------------------
# 8. Fines  (KES — skipped on re-run alongside transactions)
# ---------------------------------------------------------------------------
section("Fines (KES)")

if Fine.objects.exists():
    total_fines = Fine.objects.count()
    unpaid = Fine.objects.filter(is_paid=False, is_waived=False).count()
    paid   = Fine.objects.filter(is_paid=True).count()
    waived = Fine.objects.filter(is_waived=True).count()
    print(f"  · Skipping — {total_fines} fines already exist  ({unpaid} unpaid · {paid} paid · {waived} waived)")
elif t8 is not None:
    # days_overdue × FINE_RATE
    fine_specs = [
        # (transaction,  member_idx, days_overdue, paid,  waived)
        (t8,  7,  6,  False, False),   # Henry — KES  300  (unpaid)
        (t9,  8,  11, False, False),   # Iris  — KES  550  (unpaid)
        (t10, 9,  16, False, False),   # James — KES  800  (unpaid)
        (t11, 0,  4,  False, False),   # Alice — KES  200  (unpaid)
        (t14, 3,  7,  True,  False),   # David — KES  350  (paid)
        (t15, 4,  3,  True,  False),   # Eva   — KES  150  (paid)
        (t18, 7,  4,  False, True),    # Henry — KES  200  (waived — damaged copy)
    ]
    for tx, member_idx, days_late, is_paid, is_waived in fine_specs:
        amount = FINE_RATE * days_late
        reason = f"{'Waived — damaged copy' if is_waived else 'Overdue return'} — {days_late} day{'s' if days_late > 1 else ''} late"
        Fine.objects.get_or_create(
            transaction=tx,
            defaults=dict(
                member=member_objects[member_idx],
                amount=amount, reason=reason,
                is_paid=is_paid, is_waived=is_waived,
            ),
        )
    total_fines = Fine.objects.count()
    unpaid = Fine.objects.filter(is_paid=False, is_waived=False).count()
    paid   = Fine.objects.filter(is_paid=True).count()
    waived = Fine.objects.filter(is_waived=True).count()
    print(f"  ✓ {total_fines} fines  ·  {unpaid} unpaid  ·  {paid} paid  ·  {waived} waived")

# ---------------------------------------------------------------------------
# 9. Reservations
# ---------------------------------------------------------------------------
section("Reservations")

PENDING   = ReservationStatus.PENDING
FULFILLED = ReservationStatus.FULFILLED
CANCELLED = ReservationStatus.CANCELLED
EXPIRED   = ReservationStatus.EXPIRED

reservations_data = [
    # (member_idx, book_idx, expires_in_days, status)
    (10, 1,  5,   PENDING),
    (11, 3,  7,   PENDING),
    (0,  8,  3,   PENDING),
    (1,  13, 6,   PENDING),
    (2,  19, 4,   PENDING),
    (3,  24, 10,  FULFILLED),
    (4,  27, 15,  FULFILLED),
    (5,  4,  -3,  EXPIRED),      # expiry in the past
    (6,  15, -1,  EXPIRED),
    (7,  21, 0,   CANCELLED),
    (8,  30, 2,   CANCELLED),
]

for member_idx, book_idx, expires_delta, status in reservations_data:
    Reservation.objects.get_or_create(
        member=member_objects[member_idx],
        book=books[book_idx],
        defaults=dict(expires_at=now + timedelta(days=expires_delta), status=status),
    )

pending_count   = Reservation.objects.filter(status=PENDING).count()
fulfilled_count = Reservation.objects.filter(status=FULFILLED).count()
other_count     = Reservation.objects.filter(status__in=[CANCELLED, EXPIRED]).count()
print(f"  ✓ {Reservation.objects.count()} reservations")
print(f"    Pending: {pending_count}  ·  Fulfilled: {fulfilled_count}  ·  Cancelled/Expired: {other_count}")

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
print(f"\n{'═' * 55}")
print(f"  ✅  Seed complete!")
print(f"{'═' * 55}")
print()
print(f"  {'Role':<12} {'Email':<36} Password")
print(f"  {'─'*12} {'─'*36} {'─'*16}")
print(f"  {'Admin':<12} {'admin@booktracking.com':<36} Admin@1234")
print(f"  {'Librarian':<12} {'librarian@booktracking.com':<36} Librarian@1234")
print(f"  {'Member':<12} {'alice@example.com  (and 11 others)':<36} Member@1234")
print()
print(f"  All 12 member emails follow the pattern <name>@example.com:")
print(f"  alice, bob, carol, david, eva, frank, grace,")
print(f"  henry, iris, james, karen, liam")
print()
print(f"  Fine rate : KES {FINE_RATE}/day")
print(f"  Loan period: 14 days")
print(f"{'═' * 55}")

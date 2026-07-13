# Plain PHP Backend Rewrite Design

Date: 2026-07-13

## Goal

Replace the current Django backend with a plain PHP backend that stays compatible
with the existing React frontend.

The PHP backend must preserve the current API contract:

- Same `/api/...` route paths.
- Same JSON response envelope.
- Same camelCase response fields expected by the frontend.
- Same role behavior for admin, librarian, and member users.
- Same JWT access token plus HttpOnly `refreshToken` cookie flow.
- Same library workflows: catalog, members, borrow/return, reservations, fines,
  notifications, settings, reports, and Open Library import.

The Django backend remains available in Git history as behavior reference while
the PHP replacement is built on a feature branch.

## Decisions

- Use plain/core PHP, no framework.
- Composer is allowed for focused infrastructure packages.
- Use a fresh MySQL schema through PHP migrations and seeders.
- Support both local PHP built-in server and Apache/XAMPP.
- Build full frontend contract parity in phases.
- Keep frontend changes minimal and only fix contract mismatches if discovered.

## Runtime

Target runtime:

- PHP 8.2 or later.
- MySQL 8 or later.
- Composer.
- PDO MySQL.

Local built-in server:

```bash
composer install
php database/migrate.php
php database/seed.php
php scripts/import_openlibrary_books.php --limit=500 --copies=50
php -S localhost:8000 -t public
```

Apache/XAMPP:

- `public/` is the document root when possible.
- If hosted from the project root, `.htaccess` rewrites requests into
  `public/index.php`.
- `/api/...` URLs must resolve the same in both run modes.

## Composer Packages

Use Composer for small infrastructure only:

- `firebase/php-jwt` for JWT signing and verification.
- `vlucas/phpdotenv` for `.env` loading.
- `phpunit/phpunit` for tests.

No framework router, ORM, or full application framework.

## Project Structure

```text
public/
  index.php
  .htaccess

src/
  Core/
    Config.php
    Database.php
    Request.php
    Response.php
    Router.php
  Middleware/
    AuthMiddleware.php
    RoleMiddleware.php
  Controllers/
    AuthController.php
    BooksController.php
    CategoriesController.php
    MembersController.php
    TransactionsController.php
    ReservationsController.php
    FinesController.php
    ReportsController.php
    SettingsController.php
    NotificationsController.php
  Repositories/
    BookRepository.php
    MemberRepository.php
    TransactionRepository.php
  Services/
    AuthService.php
    TokenService.php
    BookService.php
    CirculationService.php
    ReservationService.php
    FineService.php
    ReportService.php
    OpenLibraryImportService.php

database/
  migrations/
  seeds/
  migrate.php
  seed.php

scripts/
  import_openlibrary_books.php
  mark_overdue.php

tests/
```

Controller files own HTTP concerns. Services own workflow/business rules.
Repositories own SQL. Core classes stay small and reusable.

## API Contract

All endpoints remain prefixed with `/api`.

Success response:

```json
{
  "status": "success",
  "data": {}
}
```

Paginated response:

```json
{
  "status": "success",
  "data": [],
  "meta": {
    "total": 42,
    "page": 1,
    "limit": 20,
    "totalPages": 3
  }
}
```

Error response:

```json
{
  "status": "error",
  "message": "Human-readable description"
}
```

Borrow-limit errors may include:

```json
{
  "status": "error",
  "message": "Member cannot borrow more than 5 books at once",
  "activeBorrowCount": 5,
  "maxBooks": 5,
  "remainingSlots": 0
}
```

## Routes

Authentication:

- `POST /api/auth/signup`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/refresh`
- `GET /api/auth/me`
- `PATCH /api/auth/me`
- `PATCH /api/auth/change-password`

Books and categories:

- `GET /api/books/`
- `POST /api/books/`
- `GET /api/books/{id}/`
- `PATCH /api/books/{id}/`
- `DELETE /api/books/{id}/`
- `GET /api/categories/`
- `POST /api/categories/`
- `GET /api/categories/{id}/`
- `PATCH /api/categories/{id}/`
- `DELETE /api/categories/{id}/`

Members:

- `GET /api/members/`
- `POST /api/members/`
- `GET /api/members/{id}/`
- `PATCH /api/members/{id}/`
- `DELETE /api/members/{id}/`
- `GET /api/members/{id}/transactions/`
- `GET /api/members/{id}/reservations/`
- `GET /api/members/{id}/fines/`

Transactions:

- `GET /api/transactions/`
- `POST /api/transactions/`
- `GET /api/transactions/{id}/`
- `POST /api/transactions/{id}/return/`

Reservations:

- `GET /api/reservations/`
- `POST /api/reservations/`
- `GET /api/reservations/{id}/`
- `PATCH /api/reservations/{id}/cancel/`
- `PATCH /api/reservations/{id}/fulfill/`

Fines:

- `GET /api/fines/`
- `GET /api/fines/{id}/`
- `PATCH /api/fines/{id}/pay/`
- `PATCH /api/fines/{id}/waive/`

Reports:

- `GET /api/reports/summary/`
- `GET /api/reports/borrowing/`
- `GET /api/reports/inventory/`
- `GET /api/reports/fines/`
- `GET /api/reports/overdue/`
- `GET /api/reports/popular-books/`
- `GET /api/reports/members/`
- `POST /api/reports/export`

Settings and notifications:

- `GET /api/settings/`
- `PATCH /api/settings/`
- `PUT /api/settings/`
- `GET /api/notifications/`
- `PATCH /api/notifications/{id}/read/`
- `PATCH /api/notifications/read-all/`
- `POST /api/notifications/remind/`

## Data Model

Fresh MySQL tables:

- `migration_log`
- `roles`
- `users`
- `refresh_tokens`
- `members`
- `categories`
- `books`
- `transactions`
- `transaction_items`
- `reservations`
- `fines`
- `notifications`
- `settings`

Key relationships:

- `users.role_id` references `roles.id`.
- `members.user_id` references `users.id`.
- `books.category_id` references `categories.id`.
- `transactions.member_id` references `members.id`.
- `transaction_items.transaction_id` references `transactions.id`.
- `transaction_items.book_id` references `books.id`.
- `reservations.member_id` and `reservations.book_id` reference members/books.
- `fines.member_id` references `members.id`.
- `fines.transaction_id` is nullable.
- `notifications.user_id` references `users.id`.

Book fields include Open Library enrichment:

- `cover_url`
- `openlibrary_work_key`
- `synopsis`
- `subjects` as JSON
- `language_codes` as JSON
- `edition_count`
- `rating_average`
- `rating_count`
- `want_to_read_count`
- `currently_reading_count`
- `already_read_count`

Settings keys:

- `fine_rate_per_day`
- `borrow_days`
- `max_books_per_member`
- `reservation_expiry_days`

## Authentication

Password handling:

- Hash with `password_hash()`.
- Verify with `password_verify()`.

Access tokens:

- JWT signed with `JWT_SECRET`.
- Payload includes `sub`, `email`, `role`, `iat`, `exp`.
- Lifetime default: 15 minutes.

Refresh tokens:

- Random high-entropy string.
- Store hash in `refresh_tokens`.
- Send raw token in HttpOnly cookie named `refreshToken`.
- Lifetime default: 7 days.
- Rotate on `/api/auth/refresh`.
- Revoke on logout.

Account rules:

- Inactive users cannot authenticate.
- Admin has full platform authority.
- Librarian handles day-to-day operations, including member revoke/restore.
- Member can browse/reserve books and manage own account.
- Public signup creates active member with `mustChangePassword = false`.
- Staff-created member can start with `mustChangePassword = true`.

## Authorization

Route groups use explicit role checks:

- Public: login, signup, refresh.
- Any authenticated user: me, change password, notifications.
- Admin/librarian: books, categories, members read/create/update, transactions,
  reservations, fine list/detail/pay, reports, and settings.
- Admin only: permanent member delete and fine waive.
- Librarian only in current frontend: member revoke/restore access buttons.
- Member: own reservations, own fines, own profile.

## Open Library Import

CLI script:

```bash
php scripts/import_openlibrary_books.php --limit=500 --copies=50
```

Reliability options:

```bash
php scripts/import_openlibrary_books.php --limit=500 --copies=50 --skip-work-details --timeout=60 --retries=6 --page-size=25
```

Behavior:

- Query Open Library by topic.
- Normalize title, author, ISBN, publisher, published year, cover URL.
- Create categories as needed.
- Store `total_copies` and `available_copies` using `--copies`.
- Skip duplicate ISBNs.
- Skip invalid records.
- Optionally fetch work details for synopsis and richer subjects.
- Print imported, duplicate, invalid, category-created, and detail-failure
  counts.

## Phased Build

Phase 1: Core and auth

- Composer setup.
- Front controller.
- Router/request/response/config/database.
- Migrations/seed system.
- Roles, users, settings, refresh tokens.
- Login, signup, logout, refresh, me, update me, change password.

Phase 2: Catalog

- Books/categories CRUD.
- Search/filter/sort/pagination.
- Open Library import CLI.
- Book enrichment fields.

Phase 3: Members and circulation

- Members CRUD.
- Revoke/restore.
- Admin delete member.
- Borrow transaction creation with multiple books.
- Borrowing limit enforcement.
- Return all or selected transaction items.
- Member-scoped transaction history.

Phase 4: Reservations and fines

- Reservation create/list/cancel/fulfill.
- Member-scoped reservations.
- Fines list/detail/pay/waive.
- Paid/unpaid filters and member search.

Phase 5: Notifications and reports

- Notification list/read/read-all/reminders.
- Summary, inventory, borrowing, overdue, fines, members, popular-books reports.
- CSV export.

Phase 6: Compatibility smoke

- Run current React frontend against PHP backend.
- Test admin, librarian, and member flows.
- Fix contract mismatches only.

## Testing

PHP tests:

- Core router/request/response tests.
- Auth token and cookie tests.
- Repository/service tests with test database.
- Endpoint integration tests for each resource group.
- Open Library importer tests with mocked HTTP client.

Frontend compatibility:

- `npm run build` in frontend repo.
- Smoke test login and role dashboards.
- Smoke test books, members, borrow/return, reservations, fines, reports,
  notifications, settings, and member portal.

Acceptance criteria:

- Existing frontend can run by changing only `VITE_API_URL` if needed.
- Demo seed credentials work.
- 500-book Open Library import works.
- Admin, librarian, and member journeys work.
- No Django runtime files needed for PHP backend operation.

## Replacement Strategy

Work happens on `php-backend-rewrite` branch.

During implementation, Django files may be replaced by PHP files once the phase
needs that path. Until PHP parity is ready, Django code remains useful as
reference through Git history.

Final replacement means:

- PHP backend files are primary repo contents.
- README and usage docs describe PHP setup only.
- Django-specific dependencies, settings, apps, and tests are removed.
- Frontend remains compatible with PHP backend contract.

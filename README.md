# LibraTrack - Backend (PHP)

LibraTrack Backend is a plain PHP REST API for a library management platform. It
stores and serves the data used by the React frontend: users, roles, books,
members, borrowing transactions, reservations, fines, notifications, reports, and
library settings.

The backend is designed for a prototype-ready library system where staff can
manage day-to-day circulation and members can browse, reserve, and manage their
own account.

**Note:** This is the PHP rewrite of the original Django backend.

---

## Core Capabilities

- Custom JWT authentication with HttpOnly refresh-token cookies.
- Role-based access for admin, librarian, and member accounts.
- Public member sign-up plus staff-created member accounts.
- Centralized book catalog with copy counts and availability.
- Open Library import for a larger demo catalog with covers, synopsis, subjects,
  languages, ratings, editions, and popularity data.
- Borrowing and return workflows, including multi-book transactions and partial
  returns.
- Borrowing limit enforcement through `max_books_per_member`.
- Member revoke/restore support through account activation state.
- Admin-only permanent member deletion with dependent activity cleanup.
- Reservations, fines, notifications, reports, CSV exports, and configurable
  library settings.

---

## Technology Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.2+ |
| Framework | Plain PHP with custom HTTP core |
| Database | MySQL 8 via PDO |
| Authentication | Custom JWT with php-jwt and password_hash |
| Config | Dotenv via vlucas/phpdotenv |
| HTTP | PDO for database connection pooling |
| Testing | PHPUnit 11 |

---

## Prerequisites

- PHP 8.2 or later.
- Composer (PHP package manager).
- MySQL 8.0 or later.
- A MySQL database and user with privileges on that database.

---

## Local Setup (PHP)

### 1. Install dependencies

```bash
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
```

Example local `.env`:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=libratrack_php
DB_USER=root
DB_PASSWORD=

JWT_SECRET=dev-secret-key-change-in-production
JWT_ACCESS_TTL_MINUTES=15
JWT_REFRESH_TTL_DAYS=7
COOKIE_SECURE=false
```

### 3. Create the local MySQL database

```sql
CREATE DATABASE libratrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'libratrack_user'@'localhost' IDENTIFIED BY 'libratrack_pass';
GRANT ALL PRIVILEGES ON libratrack.* TO 'libratrack_user'@'localhost';
```

If the user already exists, reset the password instead:

```sql
ALTER USER 'libratrack_user'@'localhost' IDENTIFIED BY 'libratrack_pass';
GRANT ALL PRIVILEGES ON libratrack.* TO 'libratrack_user'@'localhost';
```

### 4. Run migrations

```bash
php database/migrate.php
```

### 5. Seed baseline demo data

```bash
php database/seed.php
```

The seed command creates the small demo dataset needed to log in and test the
main workflows:

| Data | Count | Notes |
|---|---:|---|
| Roles | 3 | `admin`, `librarian`, `member` |
| Staff accounts | 2 | Admin and librarian |
| Member accounts | 2 | Alice and Bob |
| Library settings | 4 | Fine rate, borrow days, book limit, reservation expiry |

### 6. Start the built-in PHP server

```bash
php -S localhost:8000 -t public
```

The API will be available at:

```text
http://localhost:8000/api/
```

### 7. Apache/XAMPP Alternative

If serving from Apache/XAMPP, point the document root to the `public/` directory
when possible. If you must serve from the project root, the `public/.htaccess`
file rewrites `/api` routes to `public/index.php`.

---

## Demo Credentials

Created by `php database/seed.php`:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@libratrack.com` | `Admin@1234` |
| Librarian | `librarian@libratrack.com` | `Librarian@1234` |
| Member | `alice@libratrack.com` | `Member@1234` |
| Member | `bob@libratrack.com` | `Member@1234` |

---

## Resetting Local Data

To clear local data and rebuild the demo state:

```bash
php database/migrate.php down
php database/migrate.php
php database/seed.php
```

Open Library import is available through `scripts/import_openlibrary_books.php`.
Run it after migrations/seed data when you want the larger demo catalog.

---

## Project Structure

> Reflects the PHP backend through Phase 5: core/auth/settings, catalog,
> members, circulation, reservations, fines, notifications, reports, and CSV
> export.

```text
public/
├── index.php        Front controller
└── .htaccess        Apache/XAMPP rewrite to index.php

src/
├── Core/             Config, Database, Request, Response, Router, App
├── Middleware/       AuthMiddleware, RoleMiddleware
├── Controllers/      Auth, catalog, member, circulation, notification, report controllers
├── Repositories/     Data access for users, books, members, transactions, fines, reports
├── Services/         PasswordService, TokenService, AuthService, Open Library services
└── routes.php        Route table

database/
├── migrations/       PHP migration files
├── migrate.php       Migration runner
└── seed.php          Demo data seeder

tests/
├── Core/             Core class unit tests
├── Services/         Service unit tests
└── Feature/          Endpoint integration tests
```

The Django backend (`apps/`, `libratrack/`, `manage.py`, pytest `tests/`)
remains in this repo as behavior reference during the rewrite and will be
removed once PHP reaches full contract parity.

---

## API Response Envelope

Success response:

```json
{
  "status": "success",
  "data": {
    "id": 1
  }
}
```

Paginated list:

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

Some errors include extra structured fields. For example, borrow-limit failures
can include `activeBorrowCount`, `maxBooks`, and `remainingSlots`.

---

## API Overview

All endpoints are prefixed with `/api`. This documents the PHP backend contract
through Phase 5, carried over from the Django reference and matched to the
existing frontend.

### Authentication

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/auth/signup` | Public | Public member self-registration |
| POST | `/auth/login` | Public | Exchange credentials for tokens |
| POST | `/auth/logout` | Required | Revoke refresh token |
| POST | `/auth/refresh` | Cookie | Rotate refresh token and return a new access token |
| GET | `/auth/me` | Required | Return the current user |
| PATCH | `/auth/me` | Required | Update current user account details |
| PATCH | `/auth/change-password` | Required | Change password and clear must-change flag |

### Books and Categories

| Method | Endpoint | Description |
|---|---|---|
| GET | `/books/` | List books with pagination, search, filters, and sorting |
| POST | `/books/` | Create a book |
| GET | `/books/{id}/` | Retrieve a book |
| PATCH | `/books/{id}/` | Update a book |
| PUT | `/books/{id}/` | Update a book (see PUT/PATCH note below) |
| DELETE | `/books/{id}/` | Delete a book |
| GET | `/categories/` | List categories with book counts |
| POST | `/categories/` | Create a category |
| GET | `/categories/{id}/` | Retrieve a category |
| PATCH | `/categories/{id}/` | Update a category |
| PUT | `/categories/{id}/` | Update a category (see PUT/PATCH note below) |
| DELETE | `/categories/{id}/` | Delete a category |

> **PUT/PATCH note:** for both books and categories, `PUT` and `PATCH` behave
> identically here — both perform a partial update (only the fields present in
> the request body are changed). This is a deliberate simplification versus
> the Django reference implementation, whose `PUT` requires every writable
> field to be present and rejects an incomplete body with 400.

### Members

| Method | Endpoint | Description |
|---|---|---|
| GET | `/members/` | List members with pagination/search |
| POST | `/members/` | Staff-created member account |
| GET | `/members/{id}/` | Retrieve a member |
| PATCH | `/members/{id}/` | Update member/profile details |
| DELETE | `/members/{id}/` | Permanently delete member; admin only |
| GET | `/members/{id}/transactions/` | Member transaction history |
| GET | `/members/{id}/reservations/` | Member reservations |
| GET | `/members/{id}/fines/` | Member fines |

### Transactions

| Method | Endpoint | Description |
|---|---|---|
| GET | `/transactions/` | List transactions with status/member/book/search filters |
| POST | `/transactions/` | Create a borrow transaction with one or more books |
| GET | `/transactions/{id}/` | Retrieve a transaction |
| POST | `/transactions/{id}/return/` | Return all or selected transaction items |

Return selected items with:

```json
{
  "itemIds": [1, 2]
}
```

### Reservations

| Method | Endpoint | Description |
|---|---|---|
| GET | `/reservations/` | List reservations for admin/librarian users |
| POST | `/reservations/` | Create a reservation |
| GET | `/reservations/{id}/` | Retrieve a reservation |
| PATCH | `/reservations/{id}/cancel/` | Cancel a reservation |
| PATCH | `/reservations/{id}/fulfill/` | Fulfill/approve a reservation |

### Fines

| Method | Endpoint | Description |
|---|---|---|
| GET | `/fines/` | List fines with filters/search |
| GET | `/fines/{id}/` | Retrieve a fine |
| PATCH | `/fines/{id}/pay/` | Mark a fine as paid |
| PATCH | `/fines/{id}/waive/` | Waive a fine with optional note; admin only |

### Reports

| Method | Endpoint | Description |
|---|---|---|
| GET | `/reports/summary/` | Dashboard summary totals |
| GET | `/reports/borrowing/` | Active, overdue, and returned transaction counts |
| GET | `/reports/inventory/` | Book counts by category |
| GET | `/reports/fines/` | Total, paid, and unpaid fine totals |
| GET | `/reports/overdue/` | Overdue transaction detail |
| GET | `/reports/popular-books/` | Most borrowed books |
| GET | `/reports/members/` | Active/inactive member totals |
| POST | `/reports/export` | CSV export for supported reports |

CSV export request:

```json
{
  "type": "csv",
  "report": "borrowing"
}
```

Supported export reports: `borrowing`, `inventory`, `fines`, `members`,
`popular-books`.

### Settings and Notifications

| Method | Endpoint | Description |
|---|---|---|
| GET | `/settings/` | Retrieve library settings |
| PATCH | `/settings/` | Update library settings |
| GET | `/notifications/` | List notifications for the current user |
| PATCH | `/notifications/{id}/read/` | Mark one notification as read |
| PATCH | `/notifications/read-all/` | Mark all notifications as read |
| POST | `/notifications/remind/` | Generate overdue reminders for overdue transactions |

---

## Authentication Details

- Access tokens are JWTs signed with `JWT_SECRET` using HS256.
- Access token payload includes `sub`, `email`, `role`, `iat`, and `exp`.
- Access tokens last 15 minutes.
- Refresh tokens are random 64-character hex values.
- Only refresh-token hashes are stored in the database.
- Refresh tokens are sent in an HttpOnly `refreshToken` cookie.
- Refresh tokens last 7 days and are rotated on refresh.
- Passwords are hashed with bcrypt.

---

## Database Commands

| Command | Description |
|---|---|
| `php database/migrate.php` | Create core auth and settings tables |
| `php database/seed.php` | Create demo roles, accounts, and library settings |

## Open Library Import

```bash
php scripts/import_openlibrary_books.php --limit=500 --copies=50
```

Reliability options:

```bash
php scripts/import_openlibrary_books.php --limit=500 --copies=50 --skip-work-details --timeout=60 --retries=6 --page-size=25
```

| Flag | Default | Purpose |
|---|---|---|
| `--limit` | 500 | Total books to import across all topics |
| `--copies` | 50 | Copies created per imported book |
| `--page-size` | 50 | Results per Open Library API page |
| `--timeout` | 30 | HTTP timeout in seconds |
| `--retries` | 5 | Retry attempts per page fetch |
| `--skip-work-details` | off | Skip per-work synopsis/subject enrichment for a faster import |

---

## Running Tests

```bash
vendor/bin/phpunit
```

Run a focused file:

```bash
vendor/bin/phpunit tests/Feature/AuthEndpointTest.php
```

---

## Environment Variables (PHP Runtime)

| Variable | Default | Description |
|---|---|---|
| `JWT_SECRET` | `dev-secret-key-change-in-production` | JWT signing key; override outside local development |
| `JWT_ACCESS_TTL_MINUTES` | `15` | Access token lifetime in minutes |
| `JWT_REFRESH_TTL_DAYS` | `7` | Refresh token lifetime in days |
| `DB_NAME` | `libratrack_php` | MySQL schema name |
| `DB_USER` | `root` | MySQL username |
| `DB_PASSWORD` | (empty) | MySQL password |
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `COOKIE_SECURE` | `false` | Set to `true` in production with HTTPS |

---

## Known Deferred Work

The PHP backend now covers the prototype contract through notifications,
reports, and CSV exports. Remaining backend rewrite work:

- Final removal of the Django backend files after one last parity check.
- Optional hardening beyond prototype scope: richer audit logs, production
  error logging, and deployment-specific cache/session configuration.

See `docs/superpowers/specs/2026-07-13-php-backend-rewrite-design.md` and the
phase plans under `docs/superpowers/plans/` for the full roadmap.

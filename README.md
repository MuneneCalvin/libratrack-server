# LibraTrack - Backend

LibraTrack Backend is a Django REST API for a library management platform. It
stores and serves the data used by the React frontend: users, roles, books,
members, borrowing transactions, reservations, fines, notifications, reports, and
library settings.

The backend is designed for a prototype-ready library system where staff can
manage day-to-day circulation and members can browse, reserve, and manage their
own account.

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
| Framework | Django 4.2 |
| API layer | Django REST Framework 3.15 |
| Database | MySQL 8 via PyMySQL |
| Authentication | Custom JWT with PyJWT and bcrypt |
| Config | python-decouple |
| CORS | django-cors-headers |
| Testing | pytest + pytest-django |

---

## Prerequisites

- Python 3.11 or later.
- MySQL 8.0 or later.
- A MySQL database and user with privileges on that database.
- Internet access only if you want to import books from Open Library.

---

## Local Setup

### 1. Create and activate a virtual environment

```bash
python -m venv venv
source venv/bin/activate
```

On Windows:

```bash
venv\Scripts\activate
```

### 2. Install dependencies

```bash
pip install -r requirements.txt
```

If your MySQL account uses `caching_sha2_password` and PyMySQL reports that the
`cryptography` package is required, install it in the same virtual environment:

```bash
pip install cryptography
```

### 3. Configure environment

```bash
cp .env.example .env
```

Example local `.env`:

```env
SECRET_KEY=dev-secret-key-change-in-production
DEBUG=True
DATABASE_NAME=libratrack
DATABASE_USER=libratrack_user
DATABASE_PASSWORD=libratrack_pass
DATABASE_HOST=localhost
DATABASE_PORT=3306
CORS_ALLOWED_ORIGINS=http://localhost:5173
ALLOWED_HOSTS=localhost,127.0.0.1
```

### 4. Create the local MySQL database

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

### 5. Run migrations

```bash
python manage.py migrate
```

### 6. Seed baseline demo data

```bash
python manage.py seed
```

The seed command creates the small demo dataset needed to log in and test the
main workflows:

| Data | Count | Notes |
|---|---:|---|
| Roles | 3 | `admin`, `librarian`, `member` |
| Staff accounts | 2 | Admin and librarian |
| Member accounts | 2 | Alice and Bob |
| Categories | 6 | Fiction, Non-Fiction, Science, History, Technology, Arts |
| Books | 10 | Baseline manually curated titles |
| Library settings | 4 | Fine rate, borrow days, book limit, reservation expiry |
| Transactions | 2 | One active borrow and one returned late transaction |
| Fines | 1 | Late-return fine |
| Reservations | 1 | Pending reservation |
| Notifications | 3 | Borrow, fine, and reservation examples |

### 7. Optional: import 500 Open Library books

```bash
python manage.py import_openlibrary_books --limit 500 --copies 50
```

This imports book records into the local `books` table. The frontend does not
call Open Library directly during browsing; it reads the imported records from
`/api/books/`.

Imported fields include:

- Title, author, ISBN, publisher, published year, category.
- `total_copies = 50` and `available_copies = 50`.
- Cover URL.
- Open Library work key.
- Synopsis when available.
- Subjects/tags.
- Language codes.
- Edition count.
- Rating average and rating count.
- Want-to-read, currently-reading, and already-read popularity counts.

The importer skips invalid records and duplicate ISBNs. It fetches search results
by topic and, unless `--skip-work-details` is passed, fetches work-level details
for richer synopsis and subject data.

### 8. Start the API server

```bash
python manage.py runserver
```

The API will be available at:

```text
http://localhost:8000/api/
```

---

## Demo Credentials

Created by `python manage.py seed`:

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
python manage.py flush --no-input
python manage.py migrate
python manage.py seed
python manage.py import_openlibrary_books --limit 500 --copies 50
```

Skip the import command if you only need the small baseline catalog.

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `SECRET_KEY` | `dev-secret-key-change-in-production` | Django signing key; override outside local development |
| `DEBUG` | `False` | Enables Django debug mode |
| `DATABASE_NAME` | `libratrack` | MySQL schema name |
| `DATABASE_USER` | `libratrack_user` | MySQL username |
| `DATABASE_PASSWORD` | `libratrack_pass` | MySQL password |
| `DATABASE_HOST` | `localhost` | MySQL host |
| `DATABASE_PORT` | `3306` | MySQL port |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | Comma-separated allowed frontend origins |
| `ALLOWED_HOSTS` | `localhost,127.0.0.1` | Comma-separated Django hostnames |

For temporary tunnel testing, add the public tunnel origin to
`CORS_ALLOWED_ORIGINS` and the tunnel hostname to `ALLOWED_HOSTS` if the backend
is exposed directly. If the frontend proxies `/api` to the local backend, the
backend can usually keep `localhost,127.0.0.1`.

---

## Project Structure

```text
apps/
├── auth_app/        Users, roles, JWT tokens, refresh tokens, signup
├── books/           Catalog, Open Library importer, book metadata
├── categories/      Dynamic book categories
├── members/         Member profiles and member-scoped history
├── transactions/    Borrow and return records
├── reservations/    Book reservation lifecycle
├── fines/           Paid, unpaid, and waived fines
├── notifications/   In-app notifications and reminders
├── reports/         Summary, inventory, borrowing, fines, members, export
└── settings_app/    Configurable library rules

libratrack/
├── settings/
│   ├── base.py      Shared settings
│   ├── dev.py       Local development settings
│   └── test.py      Test settings
└── urls.py          Root API routing

shared/
├── pagination.py    Standard pagination response
└── response.py      Response envelope and exception handler

tests/               pytest test suite
```

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

All endpoints are prefixed with `/api`.

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
| DELETE | `/books/{id}/` | Delete a book |
| GET | `/categories/` | List categories with book counts |
| POST | `/categories/` | Create a category |
| GET | `/categories/{id}/` | Retrieve a category |
| PATCH | `/categories/{id}/` | Update a category |
| DELETE | `/categories/{id}/` | Delete a category |

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
| POST | `/notifications/remind/` | Generate/send overdue reminders |

---

## Authentication Details

- Access tokens are JWTs signed with `SECRET_KEY` using HS256.
- Access token payload includes `sub`, `email`, `role`, `iat`, and `exp`.
- Access tokens last 15 minutes.
- Refresh tokens are random 64-character hex values.
- Only refresh-token hashes are stored in the database.
- Refresh tokens are sent in an HttpOnly `refreshToken` cookie.
- Refresh tokens last 7 days and are rotated on refresh.
- Passwords are hashed with bcrypt.

---

## Management Commands

| Command | Description |
|---|---|
| `python manage.py seed` | Create demo roles, accounts, baseline books, settings, transactions, fines, reservations, and notifications |
| `python manage.py import_openlibrary_books --limit 500 --copies 50` | Import Open Library books into the local catalog |
| `python manage.py import_openlibrary_books --limit 500 --copies 50 --skip-work-details` | Faster import without per-work synopsis enrichment |
| `python manage.py mark_overdue` | Mark active transactions past due date as overdue |

Run `mark_overdue` on a schedule in a longer-lived environment so overdue status
stays accurate.

---

## Running Tests

```bash
pytest
```

Run a focused file:

```bash
pytest tests/test_transactions.py -v
```

Tests use the SQLite-backed test settings in `libratrack/settings/test.py`, so a
local MySQL database is not required for the test suite.

---

## Docker

The repo includes a `Dockerfile` and `docker-compose.yml` with:

| Service | Purpose | Port |
|---|---|---|
| `db` | MySQL database | 3306 |
| `web` | Django API | 8000 |

Create `.env` for Docker:

```env
SECRET_KEY=dev-secret-key-change-in-production
DEBUG=True
DATABASE_NAME=libratrack
DATABASE_USER=libratrack_user
DATABASE_PASSWORD=libratrack_pass
DATABASE_HOST=db
DATABASE_PORT=3306
CORS_ALLOWED_ORIGINS=http://localhost:5173
ALLOWED_HOSTS=localhost,127.0.0.1
```

`DATABASE_HOST` must be `db` inside Docker Compose.

Start services:

```bash
docker compose up --build
```

Run setup commands in another terminal:

```bash
docker compose exec web python manage.py migrate
docker compose exec web python manage.py seed
docker compose exec web python manage.py import_openlibrary_books --limit 500 --copies 50
```

Stop services:

```bash
docker compose down
```

Delete the database volume too:

```bash
docker compose down -v
```

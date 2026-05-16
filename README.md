# Book Tracking System — Backend

A RESTful API server for the Book Tracking System. Built with Django and Django REST Framework, it manages all library data — books, members, transactions, reservations, fines, and notifications — and exposes a consistent JSON API consumed by the frontend.

---

## Technology Stack

| Layer | Technology |
|---|---|
| Framework | Django 4.2 |
| API layer | Django REST Framework 3.15 |
| Database | MySQL 8 (via PyMySQL) |
| Authentication | Custom JWT (PyJWT) + bcrypt |
| Config management | python-decouple |
| CORS | django-cors-headers |
| Testing | pytest + pytest-django |

---

## Prerequisites

- Python 3.11 or later
- MySQL 8.0 or later
- A running MySQL database with a user that has full privileges on the target schema

---

## Getting Started

### 1. Create and activate a virtual environment

```bash
python -m venv venv
source venv/bin/activate        # macOS / Linux
venv\Scripts\activate           # Windows
```

### 2. Install dependencies

```bash
pip install -r requirements.txt
```

### 3. Configure environment

Copy the example file and fill in your values:

```bash
cp .env.example .env
```

Edit `.env`:

```env
SECRET_KEY=your-long-random-secret-key
DEBUG=True
DATABASE_NAME=book_tracking
DATABASE_USER=your_db_user
DATABASE_PASSWORD=your_db_password
DATABASE_HOST=localhost
DATABASE_PORT=3306
CORS_ALLOWED_ORIGINS=http://localhost:5173
```

### 4. Create the MySQL database

```sql
CREATE DATABASE book_tracking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Run migrations

```bash
python manage.py migrate
```

### 6. Seed the database

```bash
python seed.py
```

This populates the database with everything needed to use the system immediately:

| Data | Count | Details |
|---|---|---|
| Roles | 3 | `admin`, `librarian`, `member` |
| Staff accounts | 2 | Admin + Librarian (see credentials below) |
| Member accounts | 12 | `alice` → `liam` @example.com |
| Categories | 12 | Fiction, History, Fantasy, Sci-Fi, etc. |
| Books | 32 | Real titles spread across all categories |
| Library settings | 4 | KES 50/day fine · 14-day loan · 5 book limit · 7-day reservation expiry |
| Transactions | 22 | 7 active · 4 overdue · 11 returned (with realistic backdated dates) |
| Fines | 7 | 4 unpaid · 2 paid · 1 waived — all in KES |
| Reservations | 11 | 5 pending · 2 fulfilled · 2 expired · 2 cancelled |

**Test credentials:**

| Role | Email | Password |
|---|---|---|
| Admin | `admin@booktracking.com` | `Admin@1234` |
| Librarian | `librarian@booktracking.com` | `Librarian@1234` |
| Member | `alice@example.com` (or any of the 12) | `Member@1234` |

The script is safe to re-run — it uses `get_or_create` throughout so nothing is duplicated. Transactions and fines are skipped on subsequent runs since they have no natural unique key.

**To reset the database to a clean seed state:**

```bash
python manage.py flush --no-input
python seed.py
```

### 7. Start the development server

```bash
python manage.py runserver
```

The API will be available at `http://localhost:8000/api/`.

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `SECRET_KEY` | `dev-secret-key-change-in-production` | Django secret key — **always override in production** |
| `DEBUG` | `False` | Enable debug mode (never True in production) |
| `DATABASE_NAME` | `libratrack` | MySQL schema name |
| `DATABASE_USER` | `libratrack_user` | MySQL username |
| `DATABASE_PASSWORD` | `libratrack_pass` | MySQL password |
| `DATABASE_HOST` | `localhost` | MySQL host |
| `DATABASE_PORT` | `3306` | MySQL port |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | Comma-separated list of allowed frontend origins |
| `ALLOWED_HOSTS` | `localhost,127.0.0.1` | Comma-separated list of allowed Django hostnames |

---

## Project Structure

```
apps/
├── auth_app/        # Users, roles, JWT tokens, refresh tokens
├── books/           # Book catalogue
├── categories/      # Book categories
├── members/         # Library members
├── transactions/    # Borrow and return records
├── reservations/    # Book reservations
├── fines/           # Overdue fines (KES)
├── notifications/   # In-app notifications
├── reports/         # Aggregated statistics
└── settings_app/    # Configurable library rules

libratrack/
├── settings/
│   ├── base.py      # Shared settings
│   ├── dev.py       # Development overrides
│   └── test.py      # Test overrides
└── urls.py          # Root URL configuration

shared/
├── response.py      # EnvelopeRenderer + custom exception handler
└── pagination.py    # StandardPagination

tests/               # pytest test suite (one file per app)
```

---

## API Overview

All endpoints are prefixed with `/api/`. Every response is wrapped in an envelope:

```json
// Success — single object
{ "status": "success", "data": { ... } }

// Success — list with pagination
{ "status": "success", "data": [...], "meta": { "total": 42, "page": 1, "limit": 10, "totalPages": 5 } }

// Error
{ "status": "error", "message": "Human-readable description" }
```

### Authentication

| Method | Endpoint | Auth required | Description |
|---|---|---|---|
| POST | `/api/auth/login` | No | Exchange credentials for tokens |
| POST | `/api/auth/logout` | Yes | Revoke refresh token |
| POST | `/api/auth/refresh` | No | Renew access token via cookie |
| GET | `/api/auth/me` | Yes | Return current user profile |
| PATCH | `/api/auth/change-password` | Yes | Update password; clears must-change flag |

### Books

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/books/` | List books (paginated, searchable) |
| POST | `/api/books/` | Create a book |
| GET | `/api/books/{id}/` | Retrieve a book |
| PATCH | `/api/books/{id}/` | Update a book |
| DELETE | `/api/books/{id}/` | Delete a book |

### Categories

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/categories/` | List all categories |
| POST | `/api/categories/` | Create a category |

### Members

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/members/` | List members (paginated, searchable) |
| POST | `/api/members/` | Create a member (auto-generates membership number) |
| GET | `/api/members/{id}/` | Retrieve a member |
| PATCH | `/api/members/{id}/` | Update a member |
| DELETE | `/api/members/{id}/` | Delete a member (admin only) |
| GET | `/api/members/{id}/transactions/` | Member's transaction history |
| GET | `/api/members/{id}/reservations/` | Member's reservations |
| GET | `/api/members/{id}/fines/` | Member's fines |

### Transactions

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/transactions/` | List all transactions |
| POST | `/api/transactions/` | Create a borrow transaction |
| GET | `/api/transactions/{id}/` | Retrieve a transaction |
| POST | `/api/transactions/{id}/return/` | Process a return |

### Reservations

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/reservations/` | List reservations |
| POST | `/api/reservations/` | Create a reservation |
| POST | `/api/reservations/{id}/cancel/` | Cancel a reservation |
| PATCH | `/api/reservations/{id}/fulfill/` | Approve (fulfill) a reservation |

### Fines

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/fines/` | List all fines |
| PATCH | `/api/fines/{id}/pay/` | Mark a fine as paid |

### Reports

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/reports/borrowing/` | Borrowing stats (active/returned/overdue) |
| GET | `/api/reports/fines/` | Fine totals (paid/unpaid) |
| GET | `/api/reports/inventory/` | Inventory by category |
| GET | `/api/reports/popular/` | Most borrowed books |

### Settings

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/settings/` | Retrieve library settings |
| PATCH | `/api/settings/` | Update library settings |

### Notifications

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/notifications/` | List notifications for current user |
| PATCH | `/api/notifications/{id}/read/` | Mark a notification as read |

---

## Authentication Details

### Access tokens

- JWT signed with `SECRET_KEY` using HS256.
- Payload: `{ sub, email, role, iat, exp }`.
- Lifetime: **15 minutes**.
- Sent by the client in the `Authorization: Bearer <token>` header.

### Refresh tokens

- A cryptographically random 64-character hex value.
- Only the bcrypt hash is stored in the `refresh_tokens` table — the raw value is never stored.
- Sent and received via an HttpOnly `refreshToken` cookie.
- Lifetime: **7 days**.
- Rotated on every use (old token revoked, new token issued).

### Password storage

Passwords are hashed with bcrypt (12 rounds) and stored in the `password_hash` column. Django's default `AbstractBaseUser` password field is overridden to use this scheme.

---

## Running Tests

```bash
source venv/bin/activate
pytest
```

To run a specific test file:

```bash
pytest tests/test_transactions.py -v
```

The test suite uses an in-memory SQLite database (configured in `libratrack/settings/test.py`) so no MySQL instance is required for testing.

---

## Docker

A `Dockerfile` and `docker-compose.yml` are included. The compose file starts two services:

| Service | Image | Port |
|---|---|---|
| `db` | mysql:8 | 3306 |
| `web` | Built from `Dockerfile` (python:3.12-slim) | 8000 |

MySQL data is persisted in a named volume (`mysql_data`) so it survives container restarts.

### 1. Create your `.env` file

The `web` container reads from `.env` at the project root. The compose file already sets the matching database credentials, so you only need to set the Django secret key and CORS origin:

```env
SECRET_KEY=your-long-random-secret-key
DEBUG=True
DATABASE_NAME=libratrack
DATABASE_USER=libratrack_user
DATABASE_PASSWORD=libratrack_pass
DATABASE_HOST=db
DATABASE_PORT=3306
CORS_ALLOWED_ORIGINS=http://localhost:5173
```

> **Important:** `DATABASE_HOST` must be `db` (the compose service name), not `localhost`.

### 2. Build and start

```bash
docker compose up --build
```

The `web` service waits for the `db` service to pass its health check before starting, so the database is guaranteed to be ready when Django connects.

### 3. Run migrations (first time only)

In a second terminal while the containers are running:

```bash
docker compose exec web python manage.py migrate
```

### 4. Seed the database

```bash
docker compose exec web python seed.py
```

This creates all roles, staff accounts, 12 member accounts, 32 books across 12 categories, library settings, transactions, fines, and reservations — the same data as the local seed.

**Test credentials after seeding:**

| Role | Email | Password |
|---|---|---|
| Admin | `admin@booktracking.com` | `Admin@1234` |
| Librarian | `librarian@booktracking.com` | `Librarian@1234` |
| Member | `alice@example.com` (or any of the 12) | `Member@1234` |

**To reset the database and re-seed from scratch:**

```bash
docker compose exec web python manage.py flush --no-input
docker compose exec web python seed.py
```

### 5. Verify

The API will be available at `http://localhost:8000/api/`.

### Stopping

```bash
docker compose down          # stop containers, keep volume
docker compose down -v       # stop containers and delete database volume
```

### Rebuilding after dependency changes

If you add packages to `requirements.txt`, rebuild the image:

```bash
docker compose up --build
```

# Book Tracking System — Backend Usage Guide

This guide covers the day-to-day operations of the API: how to interact with each endpoint, what data is expected, and what responses to expect.

---

## Base URL

```
http://localhost:8000/api
```

All examples below use `curl`. Replace `<TOKEN>` with a valid access token obtained from the login endpoint.

---

## Authentication

### Login

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -c cookies.txt \
  -d '{"email": "admin@example.com", "password": "Admin@1234"}'
```

**Response:**
```json
{
  "status": "success",
  "data": { "accessToken": "eyJ..." }
}
```

The `refreshToken` cookie is set automatically. Store `accessToken` for subsequent requests.

---

### Get current user

```bash
curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer <TOKEN>"
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "email": "admin@example.com",
    "role": "admin",
    "memberId": null,
    "mustChangePassword": false
  }
}
```

---

### Refresh access token

The client sends the cookie automatically. Call this when a request returns `401`:

```bash
curl -X POST http://localhost:8000/api/auth/refresh \
  -b cookies.txt \
  -c cookies.txt
```

**Response:** Same shape as login — new `accessToken` in the body, new cookie set.

---

### Change password

Required for first-time members (`mustChangePassword: true`). Also available to any authenticated user.

```bash
curl -X PATCH http://localhost:8000/api/auth/change-password \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"password": "MyNewPassword@99"}'
```

Minimum length: 8 characters.

---

### Logout

```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer <TOKEN>" \
  -b cookies.txt
```

Revokes the refresh token and clears the cookie.

---

## Books

### List books

Supports `search`, `page`, and `limit` query parameters.

```bash
curl "http://localhost:8000/api/books/?search=python&page=1&limit=10" \
  -H "Authorization: Bearer <TOKEN>"
```

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "title": "Learning Python",
      "author": "Mark Lutz",
      "isbn": "9781449355739",
      "categoryId": 2,
      "categoryName": "Programming",
      "totalCopies": 3,
      "availableCopies": 2,
      "publisher": "O'Reilly Media",
      "publishedYear": 2013
    }
  ],
  "meta": { "total": 1, "page": 1, "limit": 10, "totalPages": 1 }
}
```

### Create a book

```bash
curl -X POST http://localhost:8000/api/books/ \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Clean Code",
    "author": "Robert C. Martin",
    "isbn": "9780132350884",
    "categoryId": 2,
    "totalCopies": 5,
    "publisher": "Prentice Hall",
    "publishedYear": 2008
  }'
```

### Update a book

```bash
curl -X PATCH http://localhost:8000/api/books/1/ \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"totalCopies": 6}'
```

### Delete a book

```bash
curl -X DELETE http://localhost:8000/api/books/1/ \
  -H "Authorization: Bearer <TOKEN>"
```

### Import books from Open Library

Run this from the backend project root:

```bash
python manage.py import_openlibrary_books --limit 500 --copies 50
```

If Open Library is slow or Python networking times out, use the curl-backed
variant:

```bash
python manage.py import_openlibrary_books --limit 500 --copies 50 --skip-work-details --http-client curl --timeout 60 --retries 6 --page-size 25
```

Options:

| Option | Default | Description |
| --- | --- | --- |
| `--limit` | `500` | Maximum number of valid new books to import. |
| `--copies` | `50` | Value used for both `total_copies` and `available_copies`. |
| `--page-size` | `50` | Number of Open Library search results requested per page. Lower values can avoid slow responses. |
| `--timeout` | `30` | Seconds allowed for each Open Library request. |
| `--retries` | `5` | Number of attempts before skipping a failed query or work-detail request. |
| `--http-client` | `auto` | Use `auto`, `urllib`, or `curl`. Auto uses curl when it is available. |
| `--skip-work-details` | off | Skip per-work detail requests for faster imports without synopsis enrichment. |

The command imports local `Book` records, creates missing categories, stores
`cover_url`, synopsis, subjects/tags, language codes, edition counts, ratings,
and popularity counts when Open Library metadata supports them, skips duplicate
ISBNs, and prints imported, duplicate, invalid, category-created, and work-detail
failure counts.

---

## Members

### List members

Supports `q` (name or membership number), `page`, and `limit`.

```bash
curl "http://localhost:8000/api/members/?q=alice&page=1" \
  -H "Authorization: Bearer <TOKEN>"
```

**Response data fields:**
```json
{
  "id": 3,
  "email": "alice@example.com",
  "fullName": "Alice Johnson",
  "phone": "+254 712 000 001",
  "address": "Nairobi, Kenya",
  "membershipNumber": "MEM-A3F2B1",
  "joinedAt": "2025-01-10T09:00:00Z",
  "isActive": true
}
```

### Create a member

The membership number is generated automatically — do not include it in the request.

```bash
curl -X POST http://localhost:8000/api/members/ \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "newmember@example.com",
    "password": "Library@1234",
    "fullName": "Jane Doe",
    "phone": "+254 700 000 000",
    "address": "Mombasa, Kenya"
  }'
```

**Response includes:** the created member and their auto-generated membership number. `mustChangePassword` is set to `true` automatically.

### Update a member

```bash
curl -X PATCH http://localhost:8000/api/members/3/ \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"phone": "+254 722 000 000", "isActive": false}'
```

### Member sub-resources

```bash
# Transaction history
curl http://localhost:8000/api/members/3/transactions/ \
  -H "Authorization: Bearer <TOKEN>"

# Reservations
curl http://localhost:8000/api/members/3/reservations/ \
  -H "Authorization: Bearer <TOKEN>"

# Fines
curl http://localhost:8000/api/members/3/fines/ \
  -H "Authorization: Bearer <TOKEN>"
```

---

## Transactions

### Create a borrow transaction

```bash
curl -X POST http://localhost:8000/api/transactions/ \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"memberId": 3, "bookIds": [1, 4]}'
```

The due date is calculated automatically from the `max_borrow_days` setting.

### List transactions

Supports `status` (`ACTIVE`, `OVERDUE`, `RETURNED`), `page`, `limit`.

```bash
curl "http://localhost:8000/api/transactions/?status=OVERDUE" \
  -H "Authorization: Bearer <TOKEN>"
```

**Response data fields include:** `memberName`, `borrowedAt`, `dueDate`, `status`, `items` (list of books), and `fine` (if applicable).

### Return a transaction

```bash
curl -X POST http://localhost:8000/api/transactions/7/return/ \
  -H "Authorization: Bearer <TOKEN>"
```

If the return is overdue, a fine record is created automatically.

---

## Reservations

### Create a reservation

```bash
curl -X POST http://localhost:8000/api/reservations/ \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"memberId": 3, "bookId": 2}'
```

### List reservations

Supports `status` (`PENDING`, `FULFILLED`, `CANCELLED`, `EXPIRED`), `page`, `limit`.

```bash
curl "http://localhost:8000/api/reservations/?status=PENDING" \
  -H "Authorization: Bearer <TOKEN>"
```

### Approve a reservation

```bash
curl -X PATCH http://localhost:8000/api/reservations/5/fulfill/ \
  -H "Authorization: Bearer <TOKEN>"
```

### Cancel / decline a reservation

```bash
curl -X POST http://localhost:8000/api/reservations/5/cancel/ \
  -H "Authorization: Bearer <TOKEN>"
```

---

## Fines

### List fines

```bash
curl http://localhost:8000/api/fines/ \
  -H "Authorization: Bearer <TOKEN>"
```

**Response data fields:** `memberName`, `bookTitle`, `amount` (KES), `daysOverdue`, `isPaid`, `createdAt`.

### Mark a fine as paid

```bash
curl -X PATCH http://localhost:8000/api/fines/12/pay/ \
  -H "Authorization: Bearer <TOKEN>"
```

---

## Reports

All report endpoints return a single data object (no pagination).

```bash
# Borrowing summary
curl http://localhost:8000/api/reports/borrowing/ \
  -H "Authorization: Bearer <TOKEN>"
# → { "active": 9, "returned": 47, "overdue": 3 }

# Fine totals
curl http://localhost:8000/api/reports/fines/ \
  -H "Authorization: Bearer <TOKEN>"
# → { "total": "15400.00", "paid": "9200.00", "unpaid": "6200.00" }

# Inventory by category
curl http://localhost:8000/api/reports/inventory/ \
  -H "Authorization: Bearer <TOKEN>"
# → { "totalBooks": 120, "categories": [{ "name": "Fiction", "count": 40 }, ...] }

# Most borrowed
curl http://localhost:8000/api/reports/popular/ \
  -H "Authorization: Bearer <TOKEN>"
# → [{ "id": 3, "title": "Clean Code", "borrowCount": 18 }, ...]
```

---

## Settings

### Read current settings

```bash
curl http://localhost:8000/api/settings/ \
  -H "Authorization: Bearer <TOKEN>"
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "fine_rate_per_day": "50.00",
    "max_borrow_days": "14",
    "max_books_per_member": "5",
    "reservation_expiry_days": "7"
  }
}
```

### Update settings (admin only)

```bash
curl -X PATCH http://localhost:8000/api/settings/ \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "fine_rate_per_day": "75.00",
    "max_borrow_days": "21"
  }'
```

---

## Error Responses

All errors follow the same shape:

```json
{ "status": "error", "message": "Invalid credentials" }
```

| HTTP status | Meaning |
|---|---|
| 400 | Validation error or bad request |
| 401 | Missing or invalid/expired token |
| 403 | Authenticated but insufficient permissions |
| 404 | Resource not found |
| 500 | Server error |

---

## Running Tests

```bash
source venv/bin/activate
pytest -v
```

Run a specific file:

```bash
pytest tests/test_books.py -v
```

The test settings use SQLite so no MySQL connection is required when testing.

---

## Management Commands

### Seed the database

Populates roles, a default admin user, sample books, members, and categories:

```bash
python seed.py
```

### Apply migrations

```bash
python manage.py migrate
```

### Create a migration after model changes

```bash
python manage.py makemigrations <app_name>
python manage.py migrate
```

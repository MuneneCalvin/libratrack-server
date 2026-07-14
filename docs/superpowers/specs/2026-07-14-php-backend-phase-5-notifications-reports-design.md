# PHP Backend Phase 5 Notifications and Reports Design

## Goal

Complete the next contract-parity phase of the plain PHP backend by adding notifications, overdue reminder generation, management reports, and CSV exports used by the existing frontend.

## Scope

This phase covers:

- Notification storage and current-user notification endpoints.
- Staff-triggered overdue reminder notifications.
- Management report endpoints for summary, borrowing, inventory, fines, overdue transactions, popular books, and members.
- CSV export for supported report types.

This phase does not remove Django files. Final Django cleanup remains a separate follow-up phase after PHP endpoint parity is complete.

## Contract Requirements

Keep the existing frontend contract:

- Use `/api/...` route paths.
- Use JSON envelope responses for JSON endpoints: `{"status":"success","data":...}` or `{"status":"error","message":"..."}`.
- Use camelCase response fields.
- Use JWT access token auth through existing `AuthMiddleware`.
- Use role checks through existing `RoleMiddleware`.
- Use plain/core PHP, PDO MySQL, and parameterized queries only.
- Do not add new Composer packages.

## Notifications

Add a MySQL `notifications` table with:

- `id`
- `user_id`
- `title`
- `message`
- `type`
- `is_read`
- `created_at`

`user_id` references `users.id` with `ON DELETE CASCADE`, matching Django behavior and allowing deleted members/users to drop their notifications automatically.

Add `NotificationRepository` for:

- Paginated current-user list, newest first.
- Find notification by `id` and `user_id`.
- Mark one notification read.
- Mark all notifications read for user.
- Create notification if same user/title/message/type does not already exist.
- Generate overdue reminders from `transactions.status = 'OVERDUE'`, member user account, and transaction book items.

Add `NotificationController` routes:

- `GET /api/notifications/`
  - Authenticated users only.
  - Returns only current user's notifications.
  - Paginated with existing `Pagination`.
  - Response item fields: `id`, `title`, `message`, `type`, `isRead`, `createdAt`.
- `PATCH /api/notifications/{id}/read/`
  - Authenticated users only.
  - Marks only current user's notification as read.
  - Missing or other-user notification returns 404, matching Django `get_object_or_404`.
- `PATCH /api/notifications/read-all/`
  - Authenticated users only.
  - Marks current user's unread notifications read.
  - Returns `{"message":"All notifications marked as read"}` inside the standard success envelope.
- `POST /api/notifications/remind/`
  - Admin or librarian only.
  - Finds overdue transactions.
  - Creates one `OVERDUE` notification per overdue transaction when exact user/title/message/type notification does not already exist.
  - Title: `Overdue Book Reminder`.
  - Message: `Please return {titles}. The due date was {YYYY-MM-DD}.`
  - Returns `{"sent": number}`.

## Reports

Add `ReportRepository` for aggregate queries. Keep calculations aligned with the Django reference while using the PHP schema:

- `summary()`
  - `totalBooks`: count of book titles.
  - `totalCopies`: sum of `books.total_copies`.
  - `availableBooks`: sum of `books.available_copies`.
  - `availableCopies`: same as `availableBooks`.
  - `borrowedBooks`: count of active, unreturned transaction items.
  - `reservedBooks`: count of pending reservations.
  - `totalMembers`: count of members.
  - `activeBorrows`: count of transactions with status `ACTIVE`.
  - `overdueCount`: count of transactions with status `OVERDUE`.
  - `pendingReservations`: count of pending reservations.
  - `unpaidFinesTotal`: sum of fine amounts where `status = 'unpaid'`.
- `borrowing()`: counts `ACTIVE`, `OVERDUE`, and `RETURNED` transactions.
- `inventory()`: categories sorted by book count descending, returned as `{"categories":[{"name": "...", "count": n}]}`.
- `fines()`: string totals for all, paid, and unpaid fine amounts using `fines.status`.
- `overdue()`: overdue transaction rows ordered by due date with member and book details.
- `popularBooks()`: top 20 books by transaction item count, with `id`, `title`, `author`, `borrowCount`.
- `members()`: total, active, and inactive member counts based on `users.is_active`.

Add `ReportController` routes:

- `GET /api/reports/summary/`
- `GET /api/reports/borrowing/`
- `GET /api/reports/inventory/`
- `GET /api/reports/fines/`
- `GET /api/reports/overdue/`
- `GET /api/reports/popular-books/`
- `GET /api/reports/members/`
- `POST /api/reports/export`

All report routes are admin/librarian only. Members receive 403. Unauthenticated users receive 401 through existing middleware.

## CSV Export

Current `Response` always sends JSON. Add a focused raw/CSV response path without changing existing JSON behavior.

Supported export request:

```json
{
  "type": "csv",
  "report": "borrowing"
}
```

Supported `report` values:

- `borrowing`
- `inventory`
- `fines`
- `members`
- `popular-books`

Unsupported `type` returns 400 with message `Only CSV export is supported`.
Unknown `report` returns 400 with message `Unknown report`.

CSV response requirements:

- HTTP 200.
- `Content-Type: text/csv`.
- `Content-Disposition: attachment; filename="{report}.csv"`.
- First row: `metric,value`.
- Rows match Django reference report rows.

## Error Handling

Use existing `ValidationException` for 400, 403, and 404 behavior. Let `AuthMiddleware` produce 401. Preserve `Router` behavior for unexpected errors.

## Testing

Add focused PHPUnit feature tests:

- Notification list requires authentication.
- Current user sees only own notifications.
- Mark one notification read.
- Mark all current-user notifications read.
- Other-user notification read returns 404.
- Admin/librarian can send overdue reminders.
- Member cannot send overdue reminders.
- Report summary requires staff and includes expected keys/counts.
- Borrowing, inventory, fines, overdue, popular books, and members reports return frontend-compatible payloads.
- CSV export returns `text/csv`, content disposition, and `metric,value`.
- Unknown export type/report returns 400.

Run focused tests and then the full suite.

## Implementation Boundaries

Expected files:

- `database/migrations/005_create_notifications_table.php`
- `src/Repositories/NotificationRepository.php`
- `src/Repositories/ReportRepository.php`
- `src/Controllers/NotificationController.php`
- `src/Controllers/ReportController.php`
- `src/Core/Response.php`
- `src/routes.php`
- `tests/Feature/NotificationEndpointTest.php`
- `tests/Feature/ReportEndpointTest.php`

Do not edit frontend code in this phase unless backend parity exposes a frontend-only defect. Do not delete Django files in this phase.

# PHP Backend Phase 5 Notifications and Reports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add plain PHP notifications, overdue reminders, management reports, and CSV exports with full frontend/Django contract parity.

**Architecture:** Extend current PHP layers: migration files define schema, repositories own SQL, controllers own auth/role checks and frontend mapping, `src/routes.php` wires `/api/...` paths. Add a focused raw/CSV response capability to `Response` without changing existing JSON endpoints.

**Tech Stack:** PHP 8.2+, PDO MySQL, PHPUnit, Composer autoload only; no new Composer packages.

## Global Constraints

- Use plain/core PHP, no framework.
- Preserve same `/api/...` route paths, JSON envelope, and camelCase response fields.
- MySQL 8+ via PDO, parameterized queries only.
- No new Composer packages.
- JWT access token auth uses existing `AuthMiddleware`.
- Role checks use existing `RoleMiddleware`.
- JSON endpoints return `{"status":"success","data":...}` or `{"status":"error","message":"..."}`.
- CSV export returns raw `text/csv`; do not wrap CSV in JSON.
- Do not edit frontend code in this phase unless backend parity exposes a frontend-only defect.
- Do not delete Django files in this phase.

---

## File Structure

Phase 5 creates or modifies:

```text
database/migrations/005_create_notifications_table.php
src/Core/Response.php
src/Repositories/NotificationRepository.php
src/Repositories/ReportRepository.php
src/Controllers/NotificationController.php
src/Controllers/ReportController.php
src/routes.php
tests/Feature/NotificationEndpointTest.php
tests/Feature/ReportEndpointTest.php
README.md
```

---

### Task 1: Notifications Migration

**Files:**
- Create: `database/migrations/005_create_notifications_table.php`

**Interfaces:**
- Consumes: existing `database/migrate.php`.
- Produces: `notifications` table with `user_id` FK cascade.

- [ ] **Step 1: Write migration**

Create `database/migrations/005_create_notifications_table.php`:

```php
<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_notifications_user_created (user_id, created_at),
            INDEX idx_notifications_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS notifications',
    ],
];
```

- [ ] **Step 2: Run migration**

Run:

```bash
php database/migrate.php
```

Expected when previous migrations already applied:

```text
Skipping 001_create_core_auth_tables.php
Skipping 002_create_catalog_tables.php
Skipping 003_create_transactions_and_fines_tables.php
Skipping 004_create_reservations_table.php
Applied 005_create_notifications_table.php
```

- [ ] **Step 3: Run focused schema smoke**

Run:

```bash
php -r 'require "vendor/autoload.php"; $c=LibraTrack\Core\Config::fromProjectRoot(__DIR__); $p=LibraTrack\Core\Database::fromConfig($c)->pdo(); echo (int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '\''notifications'\''")->fetchColumn(), PHP_EOL;'
```

Expected:

```text
1
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/005_create_notifications_table.php
git commit -m "feat: add notifications migration"
```

---

### Task 2: Notification Repository, Controller, Routes, and Tests

**Files:**
- Create: `src/Repositories/NotificationRepository.php`
- Create: `src/Controllers/NotificationController.php`
- Modify: `src/routes.php`
- Test: `tests/Feature/NotificationEndpointTest.php`

**Interfaces:**
- Consumes: `Pagination`, `AuthMiddleware`, `RoleMiddleware`.
- Produces: `NotificationRepository::searchForUser(int $userId, Pagination $pagination): array{rows: array, total: int}`.
- Produces: `NotificationRepository::findForUser(int $id, int $userId): ?array`.
- Produces: `NotificationRepository::markRead(int $id): void`.
- Produces: `NotificationRepository::markAllReadForUser(int $userId): void`.
- Produces: `NotificationRepository::createIfMissing(int $userId, string $title, string $message, string $type): bool`.
- Produces: `NotificationRepository::sendOverdueReminders(): int`.
- Produces: `GET /api/notifications/`.
- Produces: `PATCH /api/notifications/{id}/read/`.
- Produces: `PATCH /api/notifications/read-all/`.
- Produces: `POST /api/notifications/remind/`.

- [ ] **Step 1: Write failing feature test**

Create `tests/Feature/NotificationEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class NotificationEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $memberToken;
    private static string $secondMemberToken;
    private static string $librarianToken;
    private static string $adminToken;
    private static int $memberUserId;
    private static int $secondMemberUserId;
    private static int $memberId;
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();

        $passwords = new PasswordService();
        $emails = [
            'notif-test-member@libratrack.com',
            'notif-test-second@libratrack.com',
            'notif-test-librarian@libratrack.com',
            'notif-test-admin@libratrack.com',
        ];

        self::cleanupPriorRows($emails);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();
        $insertUser = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');

        $insertUser->execute([$roleId('member'), 'notif-test-member@libratrack.com', $passwords->hash('x')]);
        self::$memberUserId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('member'), 'notif-test-second@libratrack.com', $passwords->hash('x')]);
        self::$secondMemberUserId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('librarian'), 'notif-test-librarian@libratrack.com', $passwords->hash('x')]);
        $librarianId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('admin'), 'notif-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO members (user_id, full_name, membership_number, joined_at) VALUES (?, ?, ?, NOW())')
            ->execute([self::$memberUserId, 'Notif Test Member', 'MEM-' . strtoupper(bin2hex(random_bytes(3)))]);
        self::$memberId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO members (user_id, full_name, membership_number, joined_at) VALUES (?, ?, ?, NOW())')
            ->execute([self::$secondMemberUserId, 'Notif Second Member', 'MEM-' . strtoupper(bin2hex(random_bytes(3)))]);

        self::$pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute(['Notif Test Category ' . bin2hex(random_bytes(3))]);
        self::$categoryId = (int) self::$pdo->lastInsertId();

        $tokens = new TokenService($config);
        self::$memberToken = $tokens->issueAccessToken(['id' => self::$memberUserId, 'email' => 'notif-test-member@libratrack.com', 'role' => 'member']);
        self::$secondMemberToken = $tokens->issueAccessToken(['id' => self::$secondMemberUserId, 'email' => 'notif-test-second@libratrack.com', 'role' => 'member']);
        self::$librarianToken = $tokens->issueAccessToken(['id' => $librarianId, 'email' => 'notif-test-librarian@libratrack.com', 'role' => 'librarian']);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'notif-test-admin@libratrack.com', 'role' => 'admin']);
    }

    private static function cleanupPriorRows(array $emails): void
    {
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $memberRows = self::$pdo->prepare(
            "SELECT members.id FROM members JOIN users ON users.id = members.user_id WHERE users.email IN ({$placeholders})"
        );
        $memberRows->execute($emails);
        foreach ($memberRows->fetchAll() as $row) {
            $memberId = (int) $row['id'];
            self::$pdo->prepare('DELETE FROM notifications WHERE user_id IN (SELECT user_id FROM members WHERE id = ?)')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM fines WHERE member_id = ?')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM reservations WHERE member_id = ?')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM transaction_items WHERE transaction_id IN (SELECT id FROM transactions WHERE member_id = ?)')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM transactions WHERE member_id = ?')->execute([$memberId]);
        }
        self::$pdo->prepare("DELETE FROM members WHERE user_id IN (SELECT id FROM users WHERE email IN ({$placeholders}))")->execute($emails);
        self::$pdo->prepare("DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE email IN ({$placeholders}))")->execute($emails);
        self::$pdo->prepare("DELETE FROM users WHERE email IN ({$placeholders})")->execute($emails);
        self::$pdo->prepare("DELETE FROM books WHERE title LIKE 'Notif Test Book%'")->execute();
        self::$pdo->prepare("DELETE FROM categories WHERE name LIKE 'Notif Test Category%'")->execute();
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    private function createNotification(int $userId, string $title = 'Test Notification'): int
    {
        self::$pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $title, 'You have a test notification.', 'BORROW']);
        return (int) self::$pdo->lastInsertId();
    }

    private function createOverdueTransaction(): void
    {
        self::$pdo->prepare(
            'INSERT INTO books (category_id, title, author, isbn, total_copies, available_copies)
             VALUES (?, ?, ?, ?, 1, 0)'
        )->execute([self::$categoryId, 'Notif Test Book', 'Author', 'ISBN-' . bin2hex(random_bytes(6))]);
        $bookId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare(
            "INSERT INTO transactions (member_id, borrowed_at, due_date, status)
             VALUES (?, NOW() - INTERVAL 21 DAY, NOW() - INTERVAL 7 DAY, 'OVERDUE')"
        )->execute([self::$memberId]);
        $transactionId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO transaction_items (transaction_id, book_id) VALUES (?, ?)')
            ->execute([$transactionId, $bookId]);
    }

    public function testListRequiresAuthentication(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/notifications/', [], [], [], null));

        $this->assertSame(401, $response->statusCode);
    }

    public function testListReturnsOnlyCurrentUserNotifications(): void
    {
        $this->createNotification(self::$memberUserId, 'Own Notification');
        $this->createNotification(self::$secondMemberUserId, 'Other Notification');

        $response = $this->router()->dispatch(new Request('GET', '/api/notifications/', ['limit' => '100'], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $titles = array_column($response->payload['data'], 'title');
        $this->assertContains('Own Notification', $titles);
        $this->assertNotContains('Other Notification', $titles);
        $this->assertArrayHasKey('meta', $response->payload);
        $this->assertFalse($response->payload['data'][0]['isRead']);
        $this->assertArrayHasKey('createdAt', $response->payload['data'][0]);
    }

    public function testMarkSingleRead(): void
    {
        $id = $this->createNotification(self::$memberUserId, 'Read Me');

        $response = $this->router()->dispatch(new Request('PATCH', "/api/notifications/{$id}/read/", [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->payload['data']['isRead']);
    }

    public function testMarkAllRead(): void
    {
        $this->createNotification(self::$memberUserId, 'N1');
        $this->createNotification(self::$memberUserId, 'N2');

        $response = $this->router()->dispatch(new Request('PATCH', '/api/notifications/read-all/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('All notifications marked as read', $response->payload['data']['message']);
        $statement = self::$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $statement->execute([self::$memberUserId]);
        $this->assertSame(0, (int) $statement->fetchColumn());
    }

    public function testCannotMarkOtherUserNotificationRead(): void
    {
        $id = $this->createNotification(self::$memberUserId, 'Private');

        $response = $this->router()->dispatch(new Request('PATCH', "/api/notifications/{$id}/read/", [], ['authorization' => 'Bearer ' . self::$secondMemberToken], [], null));

        $this->assertSame(404, $response->statusCode);
    }

    public function testSendOverdueRemindersCreatesMemberNotifications(): void
    {
        $this->createOverdueTransaction();

        $response = $this->router()->dispatch(new Request('POST', '/api/notifications/remind/', [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(1, $response->payload['data']['sent']);
        $notification = self::$pdo->prepare("SELECT title, message, type FROM notifications WHERE user_id = ? AND type = 'OVERDUE'");
        $notification->execute([self::$memberUserId]);
        $row = $notification->fetch();
        $this->assertSame('Overdue Book Reminder', $row['title']);
        $this->assertStringContainsString('Notif Test Book', $row['message']);
    }

    public function testSendOverdueRemindersDeduplicatesExistingMessages(): void
    {
        $this->createOverdueTransaction();
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$librarianToken];

        $first = $router->dispatch(new Request('POST', '/api/notifications/remind/', [], $headers, [], null));
        $second = $router->dispatch(new Request('POST', '/api/notifications/remind/', [], $headers, [], null));

        $this->assertSame(200, $first->statusCode);
        $this->assertSame(200, $second->statusCode);
        $this->assertSame(0, $second->payload['data']['sent']);
    }

    public function testMemberCannotSendOverdueReminders(): void
    {
        $response = $this->router()->dispatch(new Request('POST', '/api/notifications/remind/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(403, $response->statusCode);
    }
}
```

- [ ] **Step 2: Run failing test**

Run:

```bash
vendor/bin/phpunit tests/Feature/NotificationEndpointTest.php
```

Expected before implementation: FAIL because notification routes/classes do not exist.

- [ ] **Step 3: Implement repository**

Create `src/Repositories/NotificationRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function searchForUser(int $userId, Pagination $pagination): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $count->execute([$userId]);
        $total = (int) $count->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT * FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
        $statement->execute([$id, $userId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function markRead(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
        $statement->execute([$id]);
    }

    public function markAllReadForUser(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $statement->execute([$userId]);
    }

    public function createIfMissing(int $userId, string $title, string $message, string $type): bool
    {
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = ? AND message = ? AND type = ?'
        );
        $exists->execute([$userId, $title, $message, $type]);
        if ((int) $exists->fetchColumn() > 0) {
            return false;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$userId, $title, $message, $type]);
        return true;
    }

    public function sendOverdueReminders(): int
    {
        $transactions = $this->pdo->query(
            "SELECT transactions.id, transactions.due_date, members.user_id
             FROM transactions
             JOIN members ON members.id = transactions.member_id
             WHERE transactions.status = 'OVERDUE'
             ORDER BY transactions.due_date ASC"
        )->fetchAll();

        $bookStatement = $this->pdo->prepare(
            'SELECT books.title
             FROM transaction_items
             JOIN books ON books.id = transaction_items.book_id
             WHERE transaction_items.transaction_id = ?
             ORDER BY books.title ASC'
        );

        $sent = 0;
        foreach ($transactions as $transaction) {
            $bookStatement->execute([(int) $transaction['id']]);
            $titles = array_column($bookStatement->fetchAll(), 'title');
            $titleText = $titles === [] ? 'your borrowed book' : implode(', ', $titles);
            $dueDate = (new \DateTimeImmutable($transaction['due_date']))->format('Y-m-d');
            $message = "Please return {$titleText}. The due date was {$dueDate}.";

            if ($this->createIfMissing((int) $transaction['user_id'], 'Overdue Book Reminder', $message, 'OVERDUE')) {
                $sent++;
            }
        }

        return $sent;
    }
}
```

- [ ] **Step 4: Implement controller**

Create `src/Controllers/NotificationController.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use DateTimeImmutable;
use DateTimeInterface;
use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\NotificationRepository;

final class NotificationController
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $pagination = Pagination::fromRequest($request);
        $result = $this->notifications->searchForUser((int) $payload['sub'], $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function markRead(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $notification = $this->notifications->findForUser((int) $params['id'], (int) $payload['sub']);
        if ($notification === null) {
            throw new ValidationException('Notification not found', 404);
        }

        $this->notifications->markRead((int) $params['id']);

        return Response::success($this->toFrontend($this->notifications->findForUser((int) $params['id'], (int) $payload['sub'])));
    }

    public function markAllRead(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->notifications->markAllReadForUser((int) $payload['sub']);

        return Response::success(['message' => 'All notifications marked as read']);
    }

    public function remind(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        return Response::success(['sent' => $this->notifications->sendOverdueReminders()]);
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'type' => $row['type'],
            'isRead' => (bool) $row['is_read'],
            'createdAt' => (new DateTimeImmutable($row['created_at']))->format(DateTimeInterface::ATOM),
        ];
    }
}
```

- [ ] **Step 5: Wire routes**

Modify `src/routes.php`:

```php
use LibraTrack\Controllers\NotificationController;
use LibraTrack\Repositories\NotificationRepository;
```

After `$fineController` creation, add:

```php
$notificationRepository = new NotificationRepository($pdo);
$notificationController = new NotificationController($notificationRepository, $authMiddleware, $roleMiddleware);
```

Before `return $router;`, add notification routes in this order so `read-all` is not captured by `{id}`:

```php
$router->add('GET', '/api/notifications/', fn (Request $request, array $params): Response => $notificationController->index($request));
$router->add('PATCH', '/api/notifications/read-all/', fn (Request $request, array $params): Response => $notificationController->markAllRead($request));
$router->add('POST', '/api/notifications/remind/', fn (Request $request, array $params): Response => $notificationController->remind($request));
$router->add('PATCH', '/api/notifications/{id}/read/', fn (Request $request, array $params): Response => $notificationController->markRead($request, $params));
```

- [ ] **Step 6: Run focused test**

Run:

```bash
vendor/bin/phpunit tests/Feature/NotificationEndpointTest.php
```

Expected: PASS.

- [ ] **Step 7: Run full suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: PASS, no regressions.

- [ ] **Step 8: Commit**

```bash
git add src/Repositories/NotificationRepository.php src/Controllers/NotificationController.php src/routes.php tests/Feature/NotificationEndpointTest.php
git commit -m "feat: add notification endpoints"
```

---

### Task 3: Reports, CSV Response, Routes, and Tests

**Files:**
- Modify: `src/Core/Response.php`
- Create: `src/Repositories/ReportRepository.php`
- Create: `src/Controllers/ReportController.php`
- Modify: `src/routes.php`
- Test: `tests/Feature/ReportEndpointTest.php`

**Interfaces:**
- Consumes: `AuthMiddleware`, `RoleMiddleware`.
- Produces: `Response::csv(string $body, string $filename): self`.
- Produces: `ReportRepository::summary(): array`.
- Produces: `ReportRepository::borrowing(): array`.
- Produces: `ReportRepository::inventory(): array`.
- Produces: `ReportRepository::fines(): array`.
- Produces: `ReportRepository::overdue(): array`.
- Produces: `ReportRepository::popularBooks(): array`.
- Produces: `ReportRepository::members(): array`.
- Produces: `ReportRepository::csvRows(string $report): ?array`.
- Produces all `/api/reports/...` routes from spec.

- [ ] **Step 1: Write failing feature test**

Create `tests/Feature/ReportEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class ReportEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $memberToken;
    private static string $librarianToken;
    private static string $adminToken;
    private static int $memberId;
    private static int $categoryId;
    private static int $bookId;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();
        $passwords = new PasswordService();

        $emails = ['report-test-member@libratrack.com', 'report-test-librarian@libratrack.com', 'report-test-admin@libratrack.com'];
        self::cleanupPriorRows($emails);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();
        $insertUser = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');
        $insertUser->execute([$roleId('member'), 'report-test-member@libratrack.com', $passwords->hash('x')]);
        $memberUserId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('librarian'), 'report-test-librarian@libratrack.com', $passwords->hash('x')]);
        $librarianId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('admin'), 'report-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO members (user_id, full_name, membership_number, joined_at) VALUES (?, ?, ?, NOW())')
            ->execute([$memberUserId, 'Report Test Member', 'MEM-' . strtoupper(bin2hex(random_bytes(3)))]);
        self::$memberId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute(['Report Test Category ' . bin2hex(random_bytes(3))]);
        self::$categoryId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare(
            'INSERT INTO books (category_id, title, author, isbn, total_copies, available_copies)
             VALUES (?, ?, ?, ?, 3, 2)'
        )->execute([self::$categoryId, 'Report Test Book', 'Author', 'ISBN-' . bin2hex(random_bytes(6))]);
        self::$bookId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare(
            "INSERT INTO transactions (member_id, borrowed_at, due_date, status)
             VALUES (?, NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 5 DAY, 'OVERDUE')"
        )->execute([self::$memberId]);
        $overdueTransactionId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO transaction_items (transaction_id, book_id) VALUES (?, ?)')->execute([$overdueTransactionId, self::$bookId]);

        self::$pdo->prepare(
            "INSERT INTO transactions (member_id, borrowed_at, due_date, returned_at, status)
             VALUES (?, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 1 DAY, NOW(), 'RETURNED')"
        )->execute([self::$memberId]);
        $returnedTransactionId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO transaction_items (transaction_id, book_id, returned_at) VALUES (?, ?, NOW())')->execute([$returnedTransactionId, self::$bookId]);

        self::$pdo->prepare(
            "INSERT INTO reservations (member_id, book_id, expires_at, status) VALUES (?, ?, NOW() + INTERVAL 3 DAY, 'PENDING')"
        )->execute([self::$memberId, self::$bookId]);

        self::$pdo->prepare(
            "INSERT INTO fines (member_id, transaction_id, amount, reason, status) VALUES (?, ?, '25.00', 'Report fine unpaid', 'unpaid')"
        )->execute([self::$memberId, $overdueTransactionId]);
        self::$pdo->prepare(
            "INSERT INTO fines (member_id, transaction_id, amount, reason, status) VALUES (?, NULL, '15.00', 'Report fine paid', 'paid')"
        )->execute([self::$memberId]);

        $tokens = new TokenService($config);
        self::$memberToken = $tokens->issueAccessToken(['id' => $memberUserId, 'email' => 'report-test-member@libratrack.com', 'role' => 'member']);
        self::$librarianToken = $tokens->issueAccessToken(['id' => $librarianId, 'email' => 'report-test-librarian@libratrack.com', 'role' => 'librarian']);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'report-test-admin@libratrack.com', 'role' => 'admin']);
    }

    private static function cleanupPriorRows(array $emails): void
    {
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $memberRows = self::$pdo->prepare(
            "SELECT members.id FROM members JOIN users ON users.id = members.user_id WHERE users.email IN ({$placeholders})"
        );
        $memberRows->execute($emails);
        foreach ($memberRows->fetchAll() as $row) {
            $memberId = (int) $row['id'];
            self::$pdo->prepare('DELETE FROM notifications WHERE user_id IN (SELECT user_id FROM members WHERE id = ?)')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM fines WHERE member_id = ?')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM reservations WHERE member_id = ?')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM transaction_items WHERE transaction_id IN (SELECT id FROM transactions WHERE member_id = ?)')->execute([$memberId]);
            self::$pdo->prepare('DELETE FROM transactions WHERE member_id = ?')->execute([$memberId]);
        }
        self::$pdo->prepare("DELETE FROM members WHERE user_id IN (SELECT id FROM users WHERE email IN ({$placeholders}))")->execute($emails);
        self::$pdo->prepare("DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE email IN ({$placeholders}))")->execute($emails);
        self::$pdo->prepare("DELETE FROM users WHERE email IN ({$placeholders})")->execute($emails);
        self::$pdo->prepare("DELETE FROM books WHERE title = 'Report Test Book'")->execute();
        self::$pdo->prepare("DELETE FROM categories WHERE name LIKE 'Report Test Category%'")->execute();
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    public function testSummaryRequiresAdminOrLibrarian(): void
    {
        $member = $this->router()->dispatch(new Request('GET', '/api/reports/summary/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));
        $this->assertSame(403, $member->statusCode);

        $admin = $this->router()->dispatch(new Request('GET', '/api/reports/summary/', [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(200, $admin->statusCode);
    }

    public function testSummaryPayloadMatchesFrontendContract(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/reports/summary/', [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(200, $response->statusCode);
        foreach (['totalBooks', 'totalCopies', 'availableBooks', 'availableCopies', 'borrowedBooks', 'reservedBooks', 'totalMembers', 'activeBorrows', 'overdueCount', 'pendingReservations', 'unpaidFinesTotal'] as $key) {
            $this->assertArrayHasKey($key, $response->payload['data']);
        }
        $this->assertGreaterThanOrEqual(1, $response->payload['data']['pendingReservations']);
        $this->assertGreaterThanOrEqual(1, $response->payload['data']['borrowedBooks']);
    }

    public function testBorrowingInventoryFinesAndMembersReports(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$librarianToken];

        $borrowing = $router->dispatch(new Request('GET', '/api/reports/borrowing/', [], $headers, [], null));
        $this->assertSame(200, $borrowing->statusCode);
        $this->assertArrayHasKey('overdue', $borrowing->payload['data']);

        $inventory = $router->dispatch(new Request('GET', '/api/reports/inventory/', [], $headers, [], null));
        $this->assertSame(200, $inventory->statusCode);
        $this->assertIsArray($inventory->payload['data']['categories']);

        $fines = $router->dispatch(new Request('GET', '/api/reports/fines/', [], $headers, [], null));
        $this->assertSame(200, $fines->statusCode);
        $this->assertArrayHasKey('unpaid', $fines->payload['data']);

        $members = $router->dispatch(new Request('GET', '/api/reports/members/', [], $headers, [], null));
        $this->assertSame(200, $members->statusCode);
        $this->assertArrayHasKey('activeMembers', $members->payload['data']);
    }

    public function testOverdueAndPopularReports(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken];

        $overdue = $router->dispatch(new Request('GET', '/api/reports/overdue/', [], $headers, [], null));
        $this->assertSame(200, $overdue->statusCode);
        $this->assertNotEmpty($overdue->payload['data']);
        $this->assertArrayHasKey('books', $overdue->payload['data'][0]);

        $popular = $router->dispatch(new Request('GET', '/api/reports/popular-books/', [], $headers, [], null));
        $this->assertSame(200, $popular->statusCode);
        $this->assertNotEmpty($popular->payload['data']);
        $this->assertArrayHasKey('borrowCount', $popular->payload['data'][0]);
    }

    public function testCsvExport(): void
    {
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/reports/export/',
            [],
            ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'],
            [],
            ['type' => 'csv', 'report' => 'borrowing']
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('text/csv', $response->headers['Content-Type']);
        $this->assertSame('attachment; filename="borrowing.csv"', $response->headers['Content-Disposition']);
        $this->assertStringContainsString('metric,value', $response->rawBody);
    }

    public function testCsvExportRejectsUnsupportedTypeAndUnknownReport(): void
    {
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $badType = $this->router()->dispatch(new Request('POST', '/api/reports/export/', [], $headers, [], ['type' => 'pdf', 'report' => 'borrowing']));
        $badReport = $this->router()->dispatch(new Request('POST', '/api/reports/export/', [], $headers, [], ['type' => 'csv', 'report' => 'unknown']));

        $this->assertSame(400, $badType->statusCode);
        $this->assertSame('Only CSV export is supported', $badType->payload['message']);
        $this->assertSame(400, $badReport->statusCode);
        $this->assertSame('Unknown report', $badReport->payload['message']);
    }
}
```

- [ ] **Step 2: Run failing test**

Run:

```bash
vendor/bin/phpunit tests/Feature/ReportEndpointTest.php
```

Expected before implementation: FAIL because report routes/classes and CSV response support do not exist.

- [ ] **Step 3: Add CSV/raw support to Response**

Modify `src/Core/Response.php` to include `rawBody` and content-type aware sending:

```php
public function __construct(
    public readonly array $payload,
    public readonly int $statusCode = 200,
    public readonly array $headers = [],
    public readonly ?string $rawBody = null
) {
}

public static function csv(string $body, string $filename): self
{
    return new self([], 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ], $body);
}

public function send(): void
{
    http_response_code($this->statusCode);
    if ($this->rawBody !== null) {
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->rawBody;
        return;
    }

    header('Content-Type: application/json');
    foreach ($this->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($this->payload, JSON_UNESCAPED_SLASHES);
}
```

Keep `success()`, `paginated()`, and `error()` return shapes unchanged.

- [ ] **Step 4: Implement report repository**

Create `src/Repositories/ReportRepository.php` with these exact public methods:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class ReportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function summary(): array
    {
        $totalBooks = (int) $this->pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
        $copies = $this->pdo->query('SELECT COALESCE(SUM(total_copies), 0) AS total_copies, COALESCE(SUM(available_copies), 0) AS available_copies FROM books')->fetch();
        $totalMembers = (int) $this->pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
        $activeBorrows = (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'ACTIVE'")->fetchColumn();
        $borrowedBooks = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM transaction_items
             JOIN transactions ON transactions.id = transaction_items.transaction_id
             WHERE transactions.status = 'ACTIVE' AND transaction_items.returned_at IS NULL"
        )->fetchColumn();
        $overdueCount = (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'OVERDUE'")->fetchColumn();
        $pendingReservations = (int) $this->pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'PENDING'")->fetchColumn();
        $unpaidFinesTotal = (float) $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'unpaid'")->fetchColumn();

        return [
            'totalBooks' => $totalBooks,
            'totalCopies' => (int) $copies['total_copies'],
            'availableBooks' => (int) $copies['available_copies'],
            'availableCopies' => (int) $copies['available_copies'],
            'borrowedBooks' => $borrowedBooks,
            'reservedBooks' => $pendingReservations,
            'totalMembers' => $totalMembers,
            'activeBorrows' => $activeBorrows,
            'overdueCount' => $overdueCount,
            'pendingReservations' => $pendingReservations,
            'unpaidFinesTotal' => $unpaidFinesTotal,
        ];
    }

    public function borrowing(): array
    {
        return [
            'active' => $this->countTransactions('ACTIVE'),
            'overdue' => $this->countTransactions('OVERDUE'),
            'returned' => $this->countTransactions('RETURNED'),
        ];
    }

    public function inventory(): array
    {
        $rows = $this->pdo->query(
            'SELECT categories.name, COUNT(books.id) AS count
             FROM categories
             LEFT JOIN books ON books.category_id = categories.id
             GROUP BY categories.id, categories.name
             ORDER BY count DESC, categories.name ASC'
        )->fetchAll();

        return ['categories' => array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'count' => (int) $row['count'],
        ], $rows)];
    }

    public function fines(): array
    {
        return [
            'total' => $this->money("SELECT COALESCE(SUM(amount), 0) FROM fines"),
            'paid' => $this->money("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'paid'"),
            'unpaid' => $this->money("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'unpaid'"),
        ];
    }

    public function overdue(): array
    {
        $transactions = $this->pdo->query(
            "SELECT transactions.id, transactions.member_id, transactions.due_date, members.full_name AS member_name
             FROM transactions
             JOIN members ON members.id = transactions.member_id
             WHERE transactions.status = 'OVERDUE'
             ORDER BY transactions.due_date ASC"
        )->fetchAll();

        $books = $this->pdo->prepare(
            'SELECT books.id, books.title
             FROM transaction_items
             JOIN books ON books.id = transaction_items.book_id
             WHERE transaction_items.transaction_id = ?
             ORDER BY books.title ASC'
        );

        return array_map(function (array $row) use ($books): array {
            $books->execute([(int) $row['id']]);
            return [
                'id' => (int) $row['id'],
                'memberId' => (int) $row['member_id'],
                'memberName' => $row['member_name'],
                'dueDate' => (new \DateTimeImmutable($row['due_date']))->format(\DateTimeInterface::ATOM),
                'books' => array_map(static fn (array $book): array => [
                    'id' => (int) $book['id'],
                    'title' => $book['title'],
                ], $books->fetchAll()),
            ];
        }, $transactions);
    }

    public function popularBooks(): array
    {
        $rows = $this->pdo->query(
            'SELECT books.id, books.title, books.author, COUNT(transaction_items.id) AS borrow_count
             FROM books
             LEFT JOIN transaction_items ON transaction_items.book_id = books.id
             GROUP BY books.id, books.title, books.author
             ORDER BY borrow_count DESC, books.title ASC
             LIMIT 20'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'author' => $row['author'],
            'borrowCount' => (int) $row['borrow_count'],
        ], $rows);
    }

    public function members(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
        $active = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM members JOIN users ON users.id = members.user_id WHERE users.is_active = 1'
        )->fetchColumn();

        return [
            'totalMembers' => $total,
            'activeMembers' => $active,
            'inactiveMembers' => $total - $active,
        ];
    }

    public function csvRows(string $report): ?array
    {
        return match ($report) {
            'borrowing' => array_map(static fn (string $key, int $value): array => [$key, $value], array_keys($this->borrowing()), $this->borrowing()),
            'inventory' => array_map(static fn (array $row): array => [$row['name'], $row['count']], $this->inventory()['categories']),
            'fines' => array_map(static fn (string $key, string $value): array => [$key, $value], array_keys($this->fines()), $this->fines()),
            'members' => array_map(static fn (string $key, int $value): array => [$key, $value], array_keys($this->members()), $this->members()),
            'popular-books' => array_map(static fn (array $book): array => [$book['title'], $book['borrowCount']], $this->popularBooks()),
            default => null,
        };
    }

    private function countTransactions(string $status): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE status = ?');
        $statement->execute([$status]);
        return (int) $statement->fetchColumn();
    }

    private function money(string $sql): string
    {
        return number_format((float) $this->pdo->query($sql)->fetchColumn(), 2, '.', '');
    }
}
```

- [ ] **Step 5: Implement report controller**

Create `src/Controllers/ReportController.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\ReportRepository;

final class ReportController
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function summary(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->summary());
    }

    public function borrowing(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->borrowing());
    }

    public function inventory(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->inventory());
    }

    public function fines(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->fines());
    }

    public function overdue(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->overdue());
    }

    public function popularBooks(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->popularBooks());
    }

    public function members(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->members());
    }

    public function export(Request $request): Response
    {
        $this->authorizeStaff($request);

        $type = (string) (($request->json['type'] ?? 'csv'));
        $report = (string) (($request->json['report'] ?? 'borrowing'));
        if ($type !== 'csv') {
            throw new ValidationException('Only CSV export is supported');
        }

        $rows = $this->reports->csvRows($report);
        if ($rows === null) {
            throw new ValidationException('Unknown report');
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['metric', 'value']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return Response::csv($body, "{$report}.csv");
    }

    private function authorizeStaff(Request $request): void
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);
    }
}
```

- [ ] **Step 6: Wire report routes**

Modify `src/routes.php`:

```php
use LibraTrack\Controllers\ReportController;
use LibraTrack\Repositories\ReportRepository;
```

After notification controller creation, add:

```php
$reportRepository = new ReportRepository($pdo);
$reportController = new ReportController($reportRepository, $authMiddleware, $roleMiddleware);
```

Before `return $router;`, add:

```php
$router->add('GET', '/api/reports/summary/', fn (Request $request, array $params): Response => $reportController->summary($request));
$router->add('GET', '/api/reports/borrowing/', fn (Request $request, array $params): Response => $reportController->borrowing($request));
$router->add('GET', '/api/reports/inventory/', fn (Request $request, array $params): Response => $reportController->inventory($request));
$router->add('GET', '/api/reports/fines/', fn (Request $request, array $params): Response => $reportController->fines($request));
$router->add('GET', '/api/reports/overdue/', fn (Request $request, array $params): Response => $reportController->overdue($request));
$router->add('GET', '/api/reports/popular-books/', fn (Request $request, array $params): Response => $reportController->popularBooks($request));
$router->add('GET', '/api/reports/members/', fn (Request $request, array $params): Response => $reportController->members($request));
$router->add('POST', '/api/reports/export/', fn (Request $request, array $params): Response => $reportController->export($request));
```

`Request::normalizePath()` adds trailing slash, so frontend `POST /reports/export` still matches `/api/reports/export/`.

- [ ] **Step 7: Run focused report test**

Run:

```bash
vendor/bin/phpunit tests/Feature/ReportEndpointTest.php
```

Expected: PASS.

- [ ] **Step 8: Run response/core tests**

Run:

```bash
vendor/bin/phpunit tests/Core/ResponseTest.php tests/Feature/ReportEndpointTest.php
```

Expected: PASS.

- [ ] **Step 9: Run full suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add src/Core/Response.php src/Repositories/ReportRepository.php src/Controllers/ReportController.php src/routes.php tests/Feature/ReportEndpointTest.php
git commit -m "feat: add report endpoints and CSV export"
```

---

### Task 4: README Phase 5 Documentation and Final Phase Verification

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: implemented Phase 5 endpoints.
- Produces: updated PHP backend documentation for notifications/reports and CSV export.

- [ ] **Step 1: Update README endpoint status**

In `README.md`, update PHP backend progress/status text so notifications and reports are no longer listed as future work. Ensure API table includes:

```markdown
| GET | `/notifications/` | List notifications for the current user |
| PATCH | `/notifications/{id}/read/` | Mark one notification as read |
| PATCH | `/notifications/read-all/` | Mark all notifications as read |
| POST | `/notifications/remind/` | Generate overdue reminders for overdue transactions |
| GET | `/reports/summary/` | Dashboard summary totals |
| GET | `/reports/borrowing/` | Active, overdue, and returned transaction counts |
| GET | `/reports/inventory/` | Book counts by category |
| GET | `/reports/fines/` | Total, paid, and unpaid fine totals |
| GET | `/reports/overdue/` | Overdue transaction detail |
| GET | `/reports/popular-books/` | Most borrowed books |
| GET | `/reports/members/` | Active/inactive member totals |
| POST | `/reports/export` | CSV export for supported reports |
```

- [ ] **Step 2: Document CSV export request**

Ensure README has this request body:

```json
{
  "type": "csv",
  "report": "borrowing"
}
```

Ensure supported report values are documented:

```text
borrowing, inventory, fines, members, popular-books
```

- [ ] **Step 3: Run documentation grep checks**

Run:

```bash
rg "notifications/remind|reports/export|popular-books|Only CSV export" README.md docs/superpowers/specs/2026-07-14-php-backend-phase-5-notifications-reports-design.md
```

Expected: matching lines in README/spec.

- [ ] **Step 4: Run final focused phase tests**

Run:

```bash
vendor/bin/phpunit tests/Feature/NotificationEndpointTest.php tests/Feature/ReportEndpointTest.php
```

Expected: PASS.

- [ ] **Step 5: Run full suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "docs: document notification and report endpoints"
```

---

## Final Phase 5 Review Checklist

- [ ] `php database/migrate.php` applies `005_create_notifications_table.php`.
- [ ] `GET /api/notifications/` returns only current user's notifications.
- [ ] `PATCH /api/notifications/read-all/` works and route order does not collide with `{id}`.
- [ ] `POST /api/notifications/remind/` staff-only and deduplicates exact overdue reminder messages.
- [ ] `GET /api/reports/summary/` powers admin/librarian dashboard fields.
- [ ] `POST /api/reports/export` returns raw CSV, not JSON.
- [ ] `vendor/bin/phpunit` passes.
- [ ] Existing unrelated `.gitignore` local change remains unstaged unless user asks.
- [ ] Final Django cleanup remains separate next phase.

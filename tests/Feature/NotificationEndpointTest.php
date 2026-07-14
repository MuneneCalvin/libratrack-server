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

    public static function tearDownAfterClass(): void
    {
        self::cleanupPriorRows([
            'notif-test-member@libratrack.com',
            'notif-test-second@libratrack.com',
            'notif-test-librarian@libratrack.com',
            'notif-test-admin@libratrack.com',
        ]);
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

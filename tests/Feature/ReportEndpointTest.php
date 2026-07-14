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
    private static string $csvACategoryName;
    private static string $csvZCategoryName;

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

        self::createInventoryCsvFixtures();

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
            "INSERT INTO transactions (member_id, borrowed_at, due_date, status)
             VALUES (?, NOW() - INTERVAL 2 DAY, NOW() + INTERVAL 12 DAY, 'ACTIVE')"
        )->execute([self::$memberId]);
        $activeTransactionId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO transaction_items (transaction_id, book_id) VALUES (?, ?)')->execute([$activeTransactionId, self::$bookId]);

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

    public static function tearDownAfterClass(): void
    {
        self::cleanupPriorRows(['report-test-member@libratrack.com', 'report-test-librarian@libratrack.com', 'report-test-admin@libratrack.com']);
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
        self::$pdo->prepare("DELETE FROM books WHERE title LIKE 'Report Test Book%'")->execute();
        self::$pdo->prepare("DELETE FROM categories WHERE name LIKE 'Report Test%'")->execute();
    }

    private static function createInventoryCsvFixtures(): void
    {
        $suffix = bin2hex(random_bytes(3));
        self::$csvACategoryName = 'Report Test CSV A ' . $suffix;
        self::$csvZCategoryName = 'Report Test CSV Z ' . $suffix;

        self::$pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([self::$csvACategoryName]);
        $categoryA = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([self::$csvZCategoryName]);
        $categoryZ = (int) self::$pdo->lastInsertId();

        $insertBook = self::$pdo->prepare(
            'INSERT INTO books (category_id, title, author, isbn, total_copies, available_copies)
             VALUES (?, ?, ?, ?, 1, 1)'
        );
        $insertBook->execute([$categoryA, 'Report Test Book CSV A', 'Author', 'ISBN-' . bin2hex(random_bytes(6))]);
        $insertBook->execute([$categoryZ, 'Report Test Book CSV Z1', 'Author', 'ISBN-' . bin2hex(random_bytes(6))]);
        $insertBook->execute([$categoryZ, 'Report Test Book CSV Z2', 'Author', 'ISBN-' . bin2hex(random_bytes(6))]);
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

    public function testSummaryDoesNotCountOverdueItemsAsBorrowedBooks(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken];
        $before = $router->dispatch(new Request('GET', '/api/reports/summary/', [], $headers, [], null));
        $this->assertSame(200, $before->statusCode);

        self::$pdo->prepare(
            "INSERT INTO transactions (member_id, borrowed_at, due_date, status)
             VALUES (?, NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 14 DAY, 'OVERDUE')"
        )->execute([self::$memberId]);
        $transactionId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO transaction_items (transaction_id, book_id) VALUES (?, ?)')->execute([$transactionId, self::$bookId]);

        $after = $router->dispatch(new Request('GET', '/api/reports/summary/', [], $headers, [], null));
        $this->assertSame(200, $after->statusCode);
        $this->assertSame($before->payload['data']['borrowedBooks'], $after->payload['data']['borrowedBooks']);
        $this->assertSame($before->payload['data']['overdueCount'] + 1, $after->payload['data']['overdueCount']);
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

    public function testInventoryCsvExportOrdersRowsByCategoryName(): void
    {
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/reports/export/',
            [],
            ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'],
            [],
            ['type' => 'csv', 'report' => 'inventory']
        ));

        $this->assertSame(200, $response->statusCode);
        $firstPosition = strpos($response->rawBody, self::$csvACategoryName);
        $secondPosition = strpos($response->rawBody, self::$csvZCategoryName);
        $this->assertIsInt($firstPosition);
        $this->assertIsInt($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
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

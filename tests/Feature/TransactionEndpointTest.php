<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class TransactionEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $adminToken;
    private static string $memberToken;
    private static int $memberId;
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();

        $passwords = new PasswordService();

        // Clean up leftovers from a previous run so re-running the suite is idempotent:
        // transactions/transaction_items/fines reference members via FKs with ON DELETE
        // RESTRICT, so they must be removed before the member/user rows they reference.
        $priorMemberId = self::$pdo->prepare(
            'SELECT members.id FROM members
             JOIN users ON users.id = members.user_id
             WHERE users.email = ?'
        );
        $priorMemberId->execute(['txn-test-member@libratrack.com']);
        $priorMember = $priorMemberId->fetchColumn();
        if ($priorMember !== false) {
            $priorMemberIdInt = (int) $priorMember;
            self::$pdo->prepare('DELETE FROM fines WHERE member_id = ?')->execute([$priorMemberIdInt]);
            self::$pdo->prepare(
                'DELETE FROM transaction_items WHERE transaction_id IN (SELECT id FROM transactions WHERE member_id = ?)'
            )->execute([$priorMemberIdInt]);
            self::$pdo->prepare('DELETE FROM transactions WHERE member_id = ?')->execute([$priorMemberIdInt]);
        }
        self::$pdo->prepare('DELETE FROM members WHERE user_id IN (SELECT id FROM users WHERE email = ?)')
            ->execute(['txn-test-member@libratrack.com']);
        self::$pdo->prepare('DELETE FROM books WHERE title = ?')->execute(['Txn Test Book']);
        self::$pdo->prepare("DELETE FROM categories WHERE name LIKE 'Txn Test Category%'")->execute();

        self::$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')
            ->execute(['txn-test-admin@libratrack.com', 'txn-test-member@libratrack.com']);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();

        $insertUser = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');
        $insertUser->execute([$roleId('admin'), 'txn-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('member'), 'txn-test-member@libratrack.com', $passwords->hash('x')]);
        $memberUserId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO members (user_id, full_name, membership_number, joined_at) VALUES (?, ?, ?, NOW())')
            ->execute([$memberUserId, 'Txn Test Member', 'MEM-' . strtoupper(bin2hex(random_bytes(3)))]);
        self::$memberId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute(['Txn Test Category ' . bin2hex(random_bytes(3))]);
        self::$categoryId = (int) self::$pdo->lastInsertId();

        $tokens = new TokenService($config);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'txn-test-admin@libratrack.com', 'role' => 'admin']);
        self::$memberToken = $tokens->issueAccessToken(['id' => $memberUserId, 'email' => 'txn-test-member@libratrack.com', 'role' => 'member']);
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    private function createBook(int $copies = 3): int
    {
        $statement = self::$pdo->prepare(
            'INSERT INTO books (category_id, title, author, isbn, total_copies, available_copies)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([self::$categoryId, 'Txn Test Book', 'Author', 'ISBN-' . bin2hex(random_bytes(6)), $copies, $copies]);
        return (int) self::$pdo->lastInsertId();
    }

    /**
     * Earlier tests in this class share self::$memberId and intentionally leave some
     * transactions unreturned (to exercise borrow-limit behavior). Tests below this point
     * need fresh borrowing headroom, so free up any slots consumed by prior tests first.
     */
    private function freeAllBorrowSlots(): void
    {
        $items = self::$pdo->prepare(
            'SELECT transaction_items.id, transaction_items.book_id
             FROM transaction_items
             JOIN transactions ON transactions.id = transaction_items.transaction_id
             WHERE transactions.member_id = ? AND transaction_items.returned_at IS NULL'
        );
        $items->execute([self::$memberId]);

        foreach ($items->fetchAll() as $item) {
            self::$pdo->prepare('UPDATE transaction_items SET returned_at = NOW() WHERE id = ?')->execute([$item['id']]);
            self::$pdo->prepare('UPDATE books SET available_copies = available_copies + 1 WHERE id = ?')->execute([$item['book_id']]);
        }

        self::$pdo->prepare(
            "UPDATE transactions SET status = 'RETURNED', returned_at = NOW()
             WHERE member_id = ? AND status != 'RETURNED'"
        )->execute([self::$memberId]);
    }

    public function testMemberCannotCreateTransaction(): void
    {
        $bookId = $this->createBook();
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/transactions/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['memberId' => self::$memberId, 'bookIds' => [$bookId]]
        ));

        $this->assertSame(403, $response->statusCode);
    }

    public function testCreateBorrowDecrementsAvailableCopies(): void
    {
        $bookId = $this->createBook(3);
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];

        $response = $this->router()->dispatch(new Request('POST', '/api/transactions/', [], $headers, [], [
            'memberId' => self::$memberId, 'bookIds' => [$bookId],
        ]));

        $this->assertSame(201, $response->statusCode);
        $this->assertSame('ACTIVE', $response->payload['data']['status']);
        $this->assertCount(1, $response->payload['data']['items']);

        $statement = self::$pdo->prepare('SELECT available_copies FROM books WHERE id = ?');
        $statement->execute([$bookId]);
        $this->assertSame(2, (int) $statement->fetchColumn());
    }

    public function testBorrowLimitReturnsCapacityMetadata(): void
    {
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $router = $this->router();

        $activeStatement = self::$pdo->prepare(
            "SELECT COUNT(*) FROM transaction_items
             JOIN transactions ON transactions.id = transaction_items.transaction_id
             WHERE transactions.member_id = ? AND transactions.status IN ('ACTIVE', 'OVERDUE')
               AND transaction_items.returned_at IS NULL"
        );
        $activeStatement->execute([self::$memberId]);
        $existingActiveCount = (int) $activeStatement->fetchColumn();
        $maxBooks = (int) self::$pdo->query(
            "SELECT setting_value FROM settings WHERE setting_key = 'max_books_per_member'"
        )->fetchColumn();
        $slotsToFill = max(0, $maxBooks - $existingActiveCount);

        for ($i = 0; $i < $slotsToFill; $i++) {
            $bookId = $this->createBook(1);
            $create = $router->dispatch(new Request('POST', '/api/transactions/', [], $headers, [], [
                'memberId' => self::$memberId, 'bookIds' => [$bookId],
            ]));
            $this->assertSame(201, $create->statusCode);
        }

        $overLimitBookId = $this->createBook(1);
        $response = $router->dispatch(new Request('POST', '/api/transactions/', [], $headers, [], [
            'memberId' => self::$memberId, 'bookIds' => [$overLimitBookId],
        ]));

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('error', $response->payload['status']);
        $this->assertArrayHasKey('activeBorrowCount', $response->payload);
        $this->assertArrayHasKey('maxBooks', $response->payload);
        $this->assertArrayHasKey('remainingSlots', $response->payload);
        $this->assertSame(0, $response->payload['remainingSlots']);
    }

    public function testCreateBorrowRejectsUnavailableBook(): void
    {
        $bookId = $this->createBook(0);
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];

        $response = $this->router()->dispatch(new Request('POST', '/api/transactions/', [], $headers, [], [
            'memberId' => self::$memberId, 'bookIds' => [$bookId],
        ]));

        $this->assertSame(400, $response->statusCode);
    }

    public function testReturnAllItemsMarksTransactionReturned(): void
    {
        $this->freeAllBorrowSlots();
        $bookId = $this->createBook(2);
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $router = $this->router();

        $create = $router->dispatch(new Request('POST', '/api/transactions/', [], $headers, [], [
            'memberId' => self::$memberId, 'bookIds' => [$bookId],
        ]));
        $transactionId = $create->payload['data']['id'];

        $return = $router->dispatch(new Request('POST', "/api/transactions/{$transactionId}/return/", [], $headers, [], null));

        $this->assertSame(200, $return->statusCode);
        $this->assertSame('RETURNED', $return->payload['data']['status']);

        $statement = self::$pdo->prepare('SELECT available_copies FROM books WHERE id = ?');
        $statement->execute([$bookId]);
        $this->assertSame(2, (int) $statement->fetchColumn());
    }

    public function testReturnSelectedItemsKeepsTransactionActive(): void
    {
        $this->freeAllBorrowSlots();
        $firstBookId = $this->createBook(1);
        $secondBookId = $this->createBook(1);
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $router = $this->router();

        $create = $router->dispatch(new Request('POST', '/api/transactions/', [], $headers, [], [
            'memberId' => self::$memberId, 'bookIds' => [$firstBookId, $secondBookId],
        ]));
        $transactionId = $create->payload['data']['id'];
        $firstItemId = $create->payload['data']['items'][0]['id'];

        $return = $router->dispatch(new Request('POST', "/api/transactions/{$transactionId}/return/", [], $headers, [], ['itemIds' => [$firstItemId]]));

        $this->assertSame(200, $return->statusCode);
        $this->assertSame('ACTIVE', $return->payload['data']['status']);
        $returnedItems = array_filter($return->payload['data']['items'], static fn (array $item): bool => $item['returnedAt'] !== null);
        $this->assertCount(1, $returnedItems);
    }

    public function testReturnAlreadyReturnedTransactionReturns400(): void
    {
        $this->freeAllBorrowSlots();
        $bookId = $this->createBook(1);
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $router = $this->router();

        $create = $router->dispatch(new Request('POST', '/api/transactions/', [], $headers, [], [
            'memberId' => self::$memberId, 'bookIds' => [$bookId],
        ]));
        $transactionId = $create->payload['data']['id'];

        $router->dispatch(new Request('POST', "/api/transactions/{$transactionId}/return/", [], $headers, [], null));
        $second = $router->dispatch(new Request('POST', "/api/transactions/{$transactionId}/return/", [], $headers, [], null));

        $this->assertSame(400, $second->statusCode);
    }

    public function testReturnOverdueTransactionCreatesFine(): void
    {
        $bookId = $this->createBook(1);
        $insertTransaction = self::$pdo->prepare(
            "INSERT INTO transactions (member_id, borrowed_at, due_date, status) VALUES (?, NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 6 DAY, 'ACTIVE')"
        );
        $insertTransaction->execute([self::$memberId]);
        $transactionId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO transaction_items (transaction_id, book_id) VALUES (?, ?)')->execute([$transactionId, $bookId]);
        self::$pdo->prepare('UPDATE books SET available_copies = available_copies - 1 WHERE id = ?')->execute([$bookId]);

        $headers = ['authorization' => 'Bearer ' . self::$adminToken];
        $return = $this->router()->dispatch(new Request('POST', "/api/transactions/{$transactionId}/return/", [], $headers, [], null));

        $this->assertSame(200, $return->statusCode);

        $fineStatement = self::$pdo->prepare('SELECT COUNT(*) FROM fines WHERE transaction_id = ?');
        $fineStatement->execute([$transactionId]);
        $this->assertSame(1, (int) $fineStatement->fetchColumn());
    }

    public function testMemberTransactionsEndpointRequiresSelfOrStaff(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/members/' . self::$memberId . '/transactions/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($response->payload['data']);
    }
}

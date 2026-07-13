<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class FineEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $memberToken;
    private static string $librarianToken;
    private static string $adminToken;
    private static int $memberId;
    private static int $fineId;
    private static int $transactionId;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();

        $passwords = new PasswordService();
        $priorMemberId = self::$pdo->prepare(
            'SELECT members.id FROM members
             JOIN users ON users.id = members.user_id
             WHERE users.email = ?'
        );
        $priorMemberId->execute(['fine-test-member@libratrack.com']);
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
            ->execute(['fine-test-member@libratrack.com']);
        self::$pdo->prepare('DELETE FROM users WHERE email IN (?, ?, ?)')->execute([
            'fine-test-member@libratrack.com', 'fine-test-librarian@libratrack.com', 'fine-test-admin@libratrack.com',
        ]);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();
        $insertUser = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');

        $insertUser->execute([$roleId('member'), 'fine-test-member@libratrack.com', $passwords->hash('x')]);
        $memberUserId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('librarian'), 'fine-test-librarian@libratrack.com', $passwords->hash('x')]);
        $librarianId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('admin'), 'fine-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO members (user_id, full_name, membership_number, joined_at) VALUES (?, ?, ?, NOW())')
            ->execute([$memberUserId, 'Fine Test Member', 'MEM-' . strtoupper(bin2hex(random_bytes(3)))]);
        self::$memberId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare("INSERT INTO transactions (member_id, due_date, status) VALUES (?, NOW() - INTERVAL 1 DAY, 'RETURNED')")
            ->execute([self::$memberId]);
        self::$transactionId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare("INSERT INTO fines (member_id, transaction_id, amount, reason, status) VALUES (?, ?, ?, ?, 'unpaid')")
            ->execute([self::$memberId, self::$transactionId, '30.00', 'Returned 6 day(s) late']);
        self::$fineId = (int) self::$pdo->lastInsertId();

        $tokens = new TokenService($config);
        self::$memberToken = $tokens->issueAccessToken(['id' => $memberUserId, 'email' => 'fine-test-member@libratrack.com', 'role' => 'member']);
        self::$librarianToken = $tokens->issueAccessToken(['id' => $librarianId, 'email' => 'fine-test-librarian@libratrack.com', 'role' => 'librarian']);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'fine-test-admin@libratrack.com', 'role' => 'admin']);
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    public function testListRequiresAdminOrLibrarian(): void
    {
        $memberAttempt = $this->router()->dispatch(new Request('GET', '/api/fines/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));
        $this->assertSame(403, $memberAttempt->statusCode);

        $adminAttempt = $this->router()->dispatch(new Request('GET', '/api/fines/', ['limit' => '100'], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(200, $adminAttempt->statusCode);
    }

    public function testGetFineDetail(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/fines/' . self::$fineId . '/', [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::$memberId, $response->payload['data']['memberId']);
        $this->assertSame('Fine Test Member', $response->payload['data']['memberName']);
        $this->assertFalse($response->payload['data']['isPaid']);
        $this->assertFalse($response->payload['data']['isWaived']);
    }

    public function testFilterFinesByMember(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/fines/', ['memberId' => (string) self::$memberId, 'limit' => '100'], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(200, $response->statusCode);
        foreach ($response->payload['data'] as $fine) {
            $this->assertSame(self::$memberId, $fine['memberId']);
        }
    }

    public function testSearchFinesByMemberName(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/fines/', ['q' => 'Fine Test Member'], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertNotEmpty($response->payload['data']);
    }

    public function testFilterFinesByStatus(): void
    {
        $router = $this->router();
        $unpaid = $router->dispatch(new Request('GET', '/api/fines/', ['status' => 'UNPAID', 'limit' => '100'], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(200, $unpaid->statusCode);
        foreach ($unpaid->payload['data'] as $fine) {
            $this->assertFalse($fine['isPaid']);
            $this->assertFalse($fine['isWaived']);
        }
    }

    public function testPayFine(): void
    {
        $insert = self::$pdo->prepare("INSERT INTO fines (member_id, transaction_id, amount, reason, status) VALUES (?, NULL, ?, ?, 'unpaid')");
        $insert->execute([self::$memberId, '10.00', 'Test pay fine']);
        $fineId = (int) self::$pdo->lastInsertId();

        $response = $this->router()->dispatch(new Request('PATCH', "/api/fines/{$fineId}/pay/", [], ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'], [], []));

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->payload['data']['isPaid']);
    }

    public function testWaiveFineAdminOnly(): void
    {
        $insert = self::$pdo->prepare("INSERT INTO fines (member_id, transaction_id, amount, reason, status) VALUES (?, NULL, ?, ?, 'unpaid')");
        $insert->execute([self::$memberId, '15.00', 'Test waive fine']);
        $fineId = (int) self::$pdo->lastInsertId();

        $librarianAttempt = $this->router()->dispatch(new Request('PATCH', "/api/fines/{$fineId}/waive/", [], ['authorization' => 'Bearer ' . self::$librarianToken, 'content-type' => 'application/json'], [], ['waivedNote' => 'Goodwill']));
        $this->assertSame(403, $librarianAttempt->statusCode);

        $adminAttempt = $this->router()->dispatch(new Request('PATCH', "/api/fines/{$fineId}/waive/", [], ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'], [], ['waivedNote' => 'Goodwill']));
        $this->assertSame(200, $adminAttempt->statusCode);
        $this->assertTrue($adminAttempt->payload['data']['isWaived']);
        $this->assertSame('Goodwill', $adminAttempt->payload['data']['waivedNote']);
    }

    public function testWaiveFineAcceptsFrontendNoteField(): void
    {
        $insert = self::$pdo->prepare("INSERT INTO fines (member_id, transaction_id, amount, reason, status) VALUES (?, NULL, ?, ?, 'unpaid')");
        $insert->execute([self::$memberId, '5.00', 'Test note field']);
        $fineId = (int) self::$pdo->lastInsertId();

        $response = $this->router()->dispatch(new Request('PATCH', "/api/fines/{$fineId}/waive/", [], ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'], [], ['note' => 'Frontend note']));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Frontend note', $response->payload['data']['waivedNote']);
    }

    public function testMemberScopedFinesEndpoint(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/members/' . self::$memberId . '/fines/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($response->payload['data']);
    }

    public function testGetNonexistentFineReturns404(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/fines/999999999/', [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(404, $response->statusCode);
    }
}

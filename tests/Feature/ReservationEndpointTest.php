<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class ReservationEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $memberToken;
    private static string $secondMemberToken;
    private static string $librarianToken;
    private static string $adminToken;
    private static int $memberId;
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();

        $passwords = new PasswordService();
        $priorEmails = [
            'resv-test-member@libratrack.com', 'resv-test-second-member@libratrack.com',
            'resv-test-librarian@libratrack.com', 'resv-test-admin@libratrack.com',
        ];
        self::$pdo->prepare(
            'DELETE FROM reservations WHERE member_id IN (SELECT id FROM members WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?, ?, ?)))'
        )->execute($priorEmails);
        self::$pdo->prepare('DELETE FROM users WHERE email IN (?, ?, ?, ?)')->execute($priorEmails);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();
        $insertUser = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');

        $insertUser->execute([$roleId('member'), 'resv-test-member@libratrack.com', $passwords->hash('x')]);
        $memberUserId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('member'), 'resv-test-second-member@libratrack.com', $passwords->hash('x')]);
        $secondMemberUserId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('librarian'), 'resv-test-librarian@libratrack.com', $passwords->hash('x')]);
        $librarianId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('admin'), 'resv-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare('INSERT INTO members (user_id, full_name, membership_number, joined_at) VALUES (?, ?, ?, NOW())')
            ->execute([$memberUserId, 'Resv Test Member', 'MEM-' . strtoupper(bin2hex(random_bytes(3)))]);
        self::$memberId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO members (user_id, full_name, membership_number, joined_at) VALUES (?, ?, ?, NOW())')
            ->execute([$secondMemberUserId, 'Resv Second Member', 'MEM-' . strtoupper(bin2hex(random_bytes(3)))]);

        self::$pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute(['Resv Test Category ' . bin2hex(random_bytes(3))]);
        self::$categoryId = (int) self::$pdo->lastInsertId();

        self::$pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('reservation_expiry_days', '3') ON DUPLICATE KEY UPDATE setting_value = '3'")->execute();

        $tokens = new TokenService($config);
        self::$memberToken = $tokens->issueAccessToken(['id' => $memberUserId, 'email' => 'resv-test-member@libratrack.com', 'role' => 'member']);
        self::$secondMemberToken = $tokens->issueAccessToken(['id' => $secondMemberUserId, 'email' => 'resv-test-second-member@libratrack.com', 'role' => 'member']);
        self::$librarianToken = $tokens->issueAccessToken(['id' => $librarianId, 'email' => 'resv-test-librarian@libratrack.com', 'role' => 'librarian']);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'resv-test-admin@libratrack.com', 'role' => 'admin']);
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    private function createBook(): int
    {
        $statement = self::$pdo->prepare(
            'INSERT INTO books (category_id, title, author, isbn, total_copies, available_copies) VALUES (?, ?, ?, ?, 1, 1)'
        );
        $statement->execute([self::$categoryId, 'Resv Test Book', 'Author', 'ISBN-' . bin2hex(random_bytes(6))]);
        return (int) self::$pdo->lastInsertId();
    }

    public function testListRequiresAdminOrLibrarian(): void
    {
        $memberAttempt = $this->router()->dispatch(new Request('GET', '/api/reservations/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));
        $this->assertSame(403, $memberAttempt->statusCode);

        $adminAttempt = $this->router()->dispatch(new Request('GET', '/api/reservations/', ['limit' => '100'], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(200, $adminAttempt->statusCode);
    }

    public function testMemberCreatesOwnReservation(): void
    {
        $bookId = $this->createBook();
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/reservations/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['bookId' => $bookId]
        ));

        $this->assertSame(201, $response->statusCode);
        $this->assertSame('PENDING', $response->payload['data']['status']);
        $this->assertSame(self::$memberId, $response->payload['data']['memberId']);
        $this->assertSame($bookId, $response->payload['data']['bookId']);
        $this->assertNotEmpty($response->payload['data']['expiresAt']);
    }

    public function testCreateMissingBookIdReturns400(): void
    {
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/reservations/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            []
        ));

        $this->assertSame(400, $response->statusCode);
    }

    public function testStaffCreateRequiresMemberId(): void
    {
        $bookId = $this->createBook();
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/reservations/',
            [],
            ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'],
            [],
            ['bookId' => $bookId]
        ));

        $this->assertSame(400, $response->statusCode);
    }

    public function testMemberCannotCancelAnothersReservation(): void
    {
        $router = $this->router();
        $bookId = $this->createBook();
        $create = $router->dispatch(new Request(
            'POST',
            '/api/reservations/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['bookId' => $bookId]
        ));
        $reservationId = $create->payload['data']['id'];

        $response = $router->dispatch(new Request('PATCH', "/api/reservations/{$reservationId}/cancel/", [], ['authorization' => 'Bearer ' . self::$secondMemberToken], [], null));

        $this->assertSame(403, $response->statusCode);
    }

    public function testMemberCanCancelOwnReservation(): void
    {
        $router = $this->router();
        $bookId = $this->createBook();
        $create = $router->dispatch(new Request(
            'POST',
            '/api/reservations/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['bookId' => $bookId]
        ));
        $reservationId = $create->payload['data']['id'];

        $response = $router->dispatch(new Request('PATCH', "/api/reservations/{$reservationId}/cancel/", [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('CANCELLED', $response->payload['data']['status']);
    }

    public function testMemberCannotFulfillReservation(): void
    {
        $router = $this->router();
        $bookId = $this->createBook();
        $create = $router->dispatch(new Request(
            'POST',
            '/api/reservations/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['bookId' => $bookId]
        ));
        $reservationId = $create->payload['data']['id'];

        $response = $router->dispatch(new Request('PATCH', "/api/reservations/{$reservationId}/fulfill/", [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(403, $response->statusCode);
    }

    public function testAdminCanFulfillReservation(): void
    {
        $router = $this->router();
        $bookId = $this->createBook();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $create = $router->dispatch(new Request('POST', '/api/reservations/', [], $headers, [], ['bookId' => $bookId, 'memberId' => self::$memberId]));
        $reservationId = $create->payload['data']['id'];

        $response = $router->dispatch(new Request('PATCH', "/api/reservations/{$reservationId}/fulfill/", [], $headers, [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('FULFILLED', $response->payload['data']['status']);
    }

    public function testGetNonexistentReservationReturns404(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/reservations/999999999/', [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(404, $response->statusCode);
    }

    public function testMemberScopedReservationsEndpoint(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/members/' . self::$memberId . '/reservations/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($response->payload['data']);
    }
}

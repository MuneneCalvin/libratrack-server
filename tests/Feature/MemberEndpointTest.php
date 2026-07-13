<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class MemberEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $memberToken;
    private static string $librarianToken;
    private static string $adminToken;
    private static int $memberUserId;
    private static int $memberId;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();

        $passwords = new PasswordService();
        self::$pdo->prepare('DELETE FROM users WHERE email IN (?, ?, ?)')
            ->execute(['member-test-member@libratrack.com', 'member-test-librarian@libratrack.com', 'member-test-admin@libratrack.com']);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();

        $insertUser = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');
        $insertUser->execute([$roleId('member'), 'member-test-member@libratrack.com', $passwords->hash('x')]);
        self::$memberUserId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('librarian'), 'member-test-librarian@libratrack.com', $passwords->hash('x')]);
        $librarianId = (int) self::$pdo->lastInsertId();
        $insertUser->execute([$roleId('admin'), 'member-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();

        $membershipNumber = 'MEM-' . strtoupper(bin2hex(random_bytes(3)));
        self::$pdo->prepare('INSERT INTO members (user_id, full_name, phone, address, membership_number, joined_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([self::$memberUserId, 'Test Member Person', '+1', 'Somewhere', $membershipNumber]);
        self::$memberId = (int) self::$pdo->lastInsertId();

        $tokens = new TokenService($config);
        self::$memberToken = $tokens->issueAccessToken(['id' => self::$memberUserId, 'email' => 'member-test-member@libratrack.com', 'role' => 'member']);
        self::$librarianToken = $tokens->issueAccessToken(['id' => $librarianId, 'email' => 'member-test-librarian@libratrack.com', 'role' => 'librarian']);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'member-test-admin@libratrack.com', 'role' => 'admin']);
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    public function testListRequiresAdminOrLibrarian(): void
    {
        $memberAttempt = $this->router()->dispatch(new Request('GET', '/api/members/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));
        $this->assertSame(403, $memberAttempt->statusCode);

        $adminAttempt = $this->router()->dispatch(new Request('GET', '/api/members/', ['limit' => '100'], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(200, $adminAttempt->statusCode);
    }

    public function testAdminCanCreateMember(): void
    {
        $email = 'created-' . bin2hex(random_bytes(4)) . '@libratrack.com';
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/members/',
            [],
            ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'],
            [],
            ['email' => $email, 'password' => 'Pass@1234', 'fullName' => 'Created Member']
        ));

        $this->assertSame(201, $response->statusCode);
        $this->assertSame('Created Member', $response->payload['data']['fullName']);
        $this->assertTrue($response->payload['data']['isActive']);
        $this->assertNotEmpty($response->payload['data']['membershipNumber']);
    }

    public function testMemberCanViewOwnProfileButNotAnothers(): void
    {
        $router = $this->router();

        $ownProfile = $router->dispatch(new Request('GET', '/api/members/' . self::$memberId . '/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));
        $this->assertSame(200, $ownProfile->statusCode);
        $this->assertSame('Test Member Person', $ownProfile->payload['data']['fullName']);

        $othersProfile = $router->dispatch(new Request('GET', '/api/members/999999999/', [], ['authorization' => 'Bearer ' . self::$memberToken], [], null));
        $this->assertContains($othersProfile->statusCode, [403, 404]);
    }

    public function testMemberCannotSetIsActiveOrMembershipNumberOnSelf(): void
    {
        $response = $this->router()->dispatch(new Request(
            'PATCH',
            '/api/members/' . self::$memberId . '/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['isActive' => false, 'membershipNumber' => 'MEM-HACKED']
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->payload['data']['isActive']);
        $this->assertNotSame('MEM-HACKED', $response->payload['data']['membershipNumber']);
    }

    public function testLibrarianCanRevokeAndRestoreMember(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$librarianToken, 'content-type' => 'application/json'];

        $revoke = $router->dispatch(new Request('PATCH', '/api/members/' . self::$memberId . '/', [], $headers, [], ['isActive' => false]));
        $this->assertSame(200, $revoke->statusCode);
        $this->assertFalse($revoke->payload['data']['isActive']);

        $restore = $router->dispatch(new Request('PATCH', '/api/members/' . self::$memberId . '/', [], $headers, [], ['isActive' => true]));
        $this->assertSame(200, $restore->statusCode);
        $this->assertTrue($restore->payload['data']['isActive']);
    }

    public function testLibrarianCannotDeleteMember(): void
    {
        $response = $this->router()->dispatch(new Request('DELETE', '/api/members/' . self::$memberId . '/', [], ['authorization' => 'Bearer ' . self::$librarianToken], [], null));

        $this->assertSame(403, $response->statusCode);
    }

    public function testAdminDeleteRemovesMemberAndUser(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];

        $email = 'delete-target-' . bin2hex(random_bytes(4)) . '@libratrack.com';
        $create = $router->dispatch(new Request('POST', '/api/members/', [], $headers, [], ['email' => $email, 'password' => 'Pass@1234', 'fullName' => 'Delete Target']));
        $memberId = $create->payload['data']['id'];

        $delete = $router->dispatch(new Request('DELETE', "/api/members/{$memberId}/", [], $headers, [], null));
        $this->assertSame(204, $delete->statusCode);

        $getAfterDelete = $router->dispatch(new Request('GET', "/api/members/{$memberId}/", [], $headers, [], null));
        $this->assertSame(404, $getAfterDelete->statusCode);

        $userStatement = self::$pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $userStatement->execute([$email]);
        $this->assertSame(0, (int) $userStatement->fetchColumn());
    }
}

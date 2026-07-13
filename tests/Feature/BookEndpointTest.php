<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class BookEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $memberToken;
    private static string $adminToken;
    private static int $categoryId;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();

        $passwords = new PasswordService();
        self::$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')
            ->execute(['book-test-member@libratrack.com', 'book-test-admin@libratrack.com']);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();

        $insert = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');
        $insert->execute([$roleId('member'), 'book-test-member@libratrack.com', $passwords->hash('x')]);
        $memberId = (int) self::$pdo->lastInsertId();
        $insert->execute([$roleId('admin'), 'book-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();

        $tokens = new TokenService($config);
        self::$memberToken = $tokens->issueAccessToken(['id' => $memberId, 'email' => 'book-test-member@libratrack.com', 'role' => 'member']);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'book-test-admin@libratrack.com', 'role' => 'admin']);

        $categoryName = 'Book Test Category ' . bin2hex(random_bytes(4));
        self::$pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$categoryName]);
        self::$categoryId = (int) self::$pdo->lastInsertId();
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    public function testListRequiresAuthentication(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/books/', [], [], [], null));

        $this->assertSame(401, $response->statusCode);
    }

    public function testMemberCannotCreateBook(): void
    {
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/books/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['title' => 'x', 'author' => 'y', 'isbn' => 'z', 'categoryId' => self::$categoryId]
        ));

        $this->assertSame(403, $response->statusCode);
    }

    public function testAdminCanCreateGetUpdateAndDeleteBook(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $isbn = 'ISBN-' . bin2hex(random_bytes(6));

        $create = $router->dispatch(new Request('POST', '/api/books/', [], $headers, [], [
            'title' => 'Clean Code',
            'author' => 'Robert C. Martin',
            'isbn' => $isbn,
            'categoryId' => self::$categoryId,
            'totalCopies' => 3,
            'availableCopies' => 3,
        ]));
        $this->assertSame(201, $create->statusCode);
        $this->assertSame('Clean Code', $create->payload['data']['title']);
        $this->assertSame(self::$categoryId, $create->payload['data']['categoryId']);
        $id = $create->payload['data']['id'];

        $get = $router->dispatch(new Request('GET', "/api/books/{$id}/", [], $headers, [], null));
        $this->assertSame(200, $get->statusCode);
        $this->assertSame(3, $get->payload['data']['availableCopies']);

        $update = $router->dispatch(new Request('PATCH', "/api/books/{$id}/", [], $headers, [], ['title' => 'Clean Code (Updated)']));
        $this->assertSame(200, $update->statusCode);
        $this->assertSame('Clean Code (Updated)', $update->payload['data']['title']);

        $delete = $router->dispatch(new Request('DELETE', "/api/books/{$id}/", [], $headers, [], null));
        $this->assertSame(204, $delete->statusCode);
    }

    public function testDuplicateIsbnReturns400(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $isbn = 'ISBN-' . bin2hex(random_bytes(6));
        $payload = ['title' => 'A', 'author' => 'B', 'isbn' => $isbn, 'categoryId' => self::$categoryId];

        $first = $router->dispatch(new Request('POST', '/api/books/', [], $headers, [], $payload));
        $this->assertSame(201, $first->statusCode);

        $second = $router->dispatch(new Request('POST', '/api/books/', [], $headers, [], $payload));
        $this->assertSame(400, $second->statusCode);
    }

    public function testSearchByTitle(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $uniqueTitle = 'Zzyzx Searchable Title ' . bin2hex(random_bytes(4));
        $router->dispatch(new Request('POST', '/api/books/', [], $headers, [], [
            'title' => $uniqueTitle, 'author' => 'Someone', 'isbn' => 'ISBN-' . bin2hex(random_bytes(6)), 'categoryId' => self::$categoryId,
        ]));

        $search = $router->dispatch(new Request('GET', '/api/books/', ['q' => 'Zzyzx Searchable'], $headers, [], null));

        $this->assertSame(200, $search->statusCode);
        $titles = array_column($search->payload['data'], 'title');
        $this->assertContains($uniqueTitle, $titles);
    }

    public function testFilterByAvailableTrueExcludesZeroCopyBooks(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $unavailableTitle = 'Unavailable Book ' . bin2hex(random_bytes(4));

        $created = $router->dispatch(new Request('POST', '/api/books/', [], $headers, [], [
            'title' => $unavailableTitle, 'author' => 'Someone', 'isbn' => 'ISBN-' . bin2hex(random_bytes(6)),
            'categoryId' => self::$categoryId, 'totalCopies' => 1, 'availableCopies' => 0,
        ]));
        $id = $created->payload['data']['id'];

        $available = $router->dispatch(new Request('GET', '/api/books/', ['available' => 'true', 'q' => $unavailableTitle], $headers, [], null));

        $this->assertSame(200, $available->statusCode);
        $titles = array_column($available->payload['data'], 'title');
        $this->assertNotContains($unavailableTitle, $titles);

        $router->dispatch(new Request('DELETE', "/api/books/{$id}/", [], $headers, [], null));
    }

    public function testGetNonexistentBookReturns404(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/books/999999999/', [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));

        $this->assertSame(404, $response->statusCode);
    }
}

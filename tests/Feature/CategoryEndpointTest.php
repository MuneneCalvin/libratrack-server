<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class CategoryEndpointTest extends TestCase
{
    private static \PDO $pdo;
    private static string $memberToken;
    private static string $adminToken;

    public static function setUpBeforeClass(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        self::$pdo = Database::fromConfig($config)->pdo();

        $passwords = new PasswordService();
        self::$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')
            ->execute(['category-test-member@libratrack.com', 'category-test-admin@libratrack.com']);

        $roleId = fn (string $role): int => (int) self::$pdo->query("SELECT id FROM roles WHERE name = '{$role}'")->fetchColumn();

        $insert = self::$pdo->prepare('INSERT INTO users (role_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)');
        $insert->execute([$roleId('member'), 'category-test-member@libratrack.com', $passwords->hash('x')]);
        $memberId = (int) self::$pdo->lastInsertId();
        $insert->execute([$roleId('admin'), 'category-test-admin@libratrack.com', $passwords->hash('x')]);
        $adminId = (int) self::$pdo->lastInsertId();

        $tokens = new TokenService($config);
        self::$memberToken = $tokens->issueAccessToken(['id' => $memberId, 'email' => 'category-test-member@libratrack.com', 'role' => 'member']);
        self::$adminToken = $tokens->issueAccessToken(['id' => $adminId, 'email' => 'category-test-admin@libratrack.com', 'role' => 'admin']);
    }

    private function router(): \LibraTrack\Core\Router
    {
        return require dirname(__DIR__, 2) . '/src/routes.php';
    }

    public function testListRequiresAuthentication(): void
    {
        $response = $this->router()->dispatch(new Request('GET', '/api/categories/', [], [], [], null));

        $this->assertSame(401, $response->statusCode);
    }

    public function testMemberCannotCreateCategory(): void
    {
        $response = $this->router()->dispatch(new Request(
            'POST',
            '/api/categories/',
            [],
            ['authorization' => 'Bearer ' . self::$memberToken, 'content-type' => 'application/json'],
            [],
            ['name' => 'Test Category Member']
        ));

        $this->assertSame(403, $response->statusCode);
    }

    public function testAdminCanCreateListGetUpdateAndDeleteCategory(): void
    {
        $router = $this->router();
        $name = 'Test Category ' . bin2hex(random_bytes(4));

        $create = $router->dispatch(new Request(
            'POST',
            '/api/categories/',
            [],
            ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'],
            [],
            ['name' => $name]
        ));
        $this->assertSame(201, $create->statusCode);
        $this->assertSame($name, $create->payload['data']['name']);
        $id = $create->payload['data']['id'];

        $list = $router->dispatch(new Request('GET', '/api/categories/', ['limit' => '100'], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(200, $list->statusCode);
        $names = array_column($list->payload['data'], 'name');
        $this->assertContains($name, $names);

        $get = $router->dispatch(new Request('GET', "/api/categories/{$id}/", [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(200, $get->statusCode);
        $this->assertSame(0, $get->payload['data']['bookCount']);

        $update = $router->dispatch(new Request(
            'PATCH',
            "/api/categories/{$id}/",
            [],
            ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'],
            [],
            ['name' => $name . ' Updated']
        ));
        $this->assertSame(200, $update->statusCode);
        $this->assertSame($name . ' Updated', $update->payload['data']['name']);

        $delete = $router->dispatch(new Request('DELETE', "/api/categories/{$id}/", [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(204, $delete->statusCode);

        $getAfterDelete = $router->dispatch(new Request('GET', "/api/categories/{$id}/", [], ['authorization' => 'Bearer ' . self::$adminToken], [], null));
        $this->assertSame(404, $getAfterDelete->statusCode);
    }

    public function testCreateWithDuplicateNameReturns400(): void
    {
        $router = $this->router();
        $name = 'Duplicate Category ' . bin2hex(random_bytes(4));
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];

        $first = $router->dispatch(new Request('POST', '/api/categories/', [], $headers, [], ['name' => $name]));
        $this->assertSame(201, $first->statusCode);

        $second = $router->dispatch(new Request('POST', '/api/categories/', [], $headers, [], ['name' => $name]));
        $this->assertSame(400, $second->statusCode);
        $this->assertSame('error', $second->payload['status']);
    }

    public function testDeleteCategoryWithBooksReturns400(): void
    {
        $router = $this->router();
        $headers = ['authorization' => 'Bearer ' . self::$adminToken, 'content-type' => 'application/json'];
        $name = 'Category With Book ' . bin2hex(random_bytes(4));

        $category = $router->dispatch(new Request('POST', '/api/categories/', [], $headers, [], ['name' => $name]));
        $categoryId = $category->payload['data']['id'];

        $insertBook = self::$pdo->prepare(
            'INSERT INTO books (category_id, title, author, isbn, total_copies, available_copies)
             VALUES (?, ?, ?, ?, 1, 1)'
        );
        $insertBook->execute([$categoryId, 'Temp Book', 'Temp Author', 'ISBN-' . bin2hex(random_bytes(6))]);

        $delete = $router->dispatch(new Request('DELETE', "/api/categories/{$categoryId}/", [], $headers, [], null));

        $this->assertSame(400, $delete->statusCode);
    }
}

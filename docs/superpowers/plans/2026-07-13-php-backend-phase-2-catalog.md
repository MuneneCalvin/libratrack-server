# Plain PHP Backend Phase 2 Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the catalog layer of the PHP backend — Categories and Books CRUD with search/filter/sort/pagination, role-gated writes, and the Open Library import CLI — matching the existing Django reference implementation's contract exactly.

**Architecture:** Extends the Phase 1 foundation (Router/Request/Response/Config/Database, AuthMiddleware/RoleMiddleware, AuthService). This phase adds a `Pagination` core helper, a `categories`/`books` migration, `CategoryRepository`/`BookRepository` (PDO, parameterized SQL), `CategoryController`/`BookController` (role-gated via the now-wired middleware), and an Open Library import pipeline split into a pure-logic normalizer (unit-testable without network) and a curl-based HTTP fetch layer, driven by a CLI script.

**Tech Stack:** PHP 8.2+, PDO MySQL, PHPUnit, ext-curl (Open Library HTTP fetch — always available for PHP 8.2+ core builds, no Composer package needed).

## Global Constraints

- Use plain/core PHP, no framework.
- Preserve same `/api/...` route paths.
- Preserve same JSON response envelope: `{"status":"success","data":...}` / paginated adds `"meta":{"total","page","limit","totalPages"}` / `{"status":"error","message":...}`.
- Preserve same camelCase response fields expected by the frontend.
- MySQL 8+ via PDO, parameterized queries only — no string-interpolated SQL.
- Route groups: `GET /api/categories/`, `GET /api/books/`, and single-resource `GET` are any authenticated user (admin, librarian, or member); `POST`/`PATCH`/`PUT` on categories and books are admin or librarian; `DELETE` on categories and books is admin only.
- Pagination: query params `page` (default `1`) and `limit` (default `10`, max `100`), response `meta.totalPages = ceil(total / limit)`.
- Category cannot be deleted while it has books (matches Django's `on_delete=PROTECT`) — return a 400 error, not a raw DB error.
- Book ISBN and category name must be unique — duplicate create/update returns 400 with a human-readable message, not a raw DB error.

---

## File Structure

Phase 2 creates or modifies:

```text
src/Core/Pagination.php
src/Middleware/AuthMiddleware.php        (unchanged interface, now actually wired)
src/Controllers/AuthController.php       (modified: delegates to AuthMiddleware)
src/Controllers/CategoryController.php
src/Controllers/BookController.php
src/Repositories/CategoryRepository.php
src/Repositories/BookRepository.php
src/Services/OpenLibraryNormalizer.php
src/Services/OpenLibraryClient.php
src/Services/OpenLibraryImportService.php
src/routes.php                            (modified: categories/books routes + middleware wiring)
database/migrations/002_create_catalog_tables.php
scripts/import_openlibrary_books.php
tests/Middleware/AuthMiddlewareTest.php
tests/Core/PaginationTest.php
tests/Services/OpenLibraryNormalizerTest.php
tests/Feature/CategoryEndpointTest.php
tests/Feature/BookEndpointTest.php
```

Phase 2 removes no Django files. Replacement cleanup happens after all phases reach parity (Phase 6 per the design doc).

---

### Task 1: Consolidate Auth Through AuthMiddleware

**Files:**
- Modify: `src/Controllers/AuthController.php`
- Modify: `src/routes.php`
- Create: `tests/Middleware/AuthMiddlewareTest.php`

**Interfaces:**
- Consumes: `LibraTrack\Middleware\AuthMiddleware::authenticate(Request $request): array` (already exists from Phase 1, throws `ValidationException('Authentication required', 401)` on missing token, and — after the Phase 1 final-review fix — 401 on expired/invalid token).
- Produces: `AuthController` constructed with an `AuthMiddleware` dependency instead of decoding tokens itself.

Phase 1's final review flagged that `AuthController::requirePayload` duplicates `AuthMiddleware::authenticate` line-for-line, and that Book/Category controllers in this phase will need the same authenticated-payload lookup. This task removes the duplication before it spreads to two more controllers.

- [ ] **Step 1: Write failing AuthMiddleware test**

Create `tests/Middleware/AuthMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use LibraTrack\Core\Config;
use LibraTrack\Core\Request;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private function config(): Config
    {
        return new Config([
            'JWT_SECRET' => 'unit-test-secret-that-is-long-enough-for-hs256',
            'JWT_ACCESS_TTL_MINUTES' => '15',
            'JWT_REFRESH_TTL_DAYS' => '7',
        ]);
    }

    public function testAuthenticateReturnsDecodedPayloadForValidToken(): void
    {
        $tokens = new TokenService($this->config());
        $token = $tokens->issueAccessToken(['id' => 3, 'email' => 'a@b.com', 'role' => 'member']);
        $middleware = new AuthMiddleware($tokens);

        $request = new Request('GET', '/api/books/', [], ['authorization' => "Bearer {$token}"], [], null);

        $payload = $middleware->authenticate($request);

        $this->assertSame('3', $payload['sub']);
        $this->assertSame('member', $payload['role']);
    }

    public function testAuthenticateThrows401WhenTokenMissing(): void
    {
        $middleware = new AuthMiddleware(new TokenService($this->config()));
        $request = new Request('GET', '/api/books/', [], [], [], null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionCode(0);

        try {
            $middleware->authenticate($request);
        } catch (ValidationException $exception) {
            $this->assertSame(401, $exception->statusCode);
            throw $exception;
        }
    }

    public function testAuthenticateThrows401WhenTokenExpired(): void
    {
        $secret = 'unit-test-secret-that-is-long-enough-for-hs256';
        $expired = JWT::encode([
            'sub' => '3',
            'email' => 'a@b.com',
            'role' => 'member',
            'iat' => time() - 1000,
            'exp' => time() - 500,
        ], $secret, 'HS256');
        $middleware = new AuthMiddleware(new TokenService($this->config()));
        $request = new Request('GET', '/api/books/', [], ['authorization' => "Bearer {$expired}"], [], null);

        $this->expectException(ValidationException::class);

        try {
            $middleware->authenticate($request);
        } catch (ValidationException $exception) {
            $this->assertSame(401, $exception->statusCode);
            throw $exception;
        }
    }
}
```

- [ ] **Step 2: Run test to verify current behavior**

Run:

```bash
vendor/bin/phpunit tests/Middleware/AuthMiddlewareTest.php
```

Expected: PASS already, since `AuthMiddleware::authenticate` and the 401-on-expiry fix both exist from Phase 1. This test exists to lock in the contract before this task wires it into `AuthController` and two new controllers.

- [ ] **Step 3: Update AuthController to use AuthMiddleware**

Modify `src/Controllers/AuthController.php`: add `AuthMiddleware $authMiddleware` as a constructor parameter, replace the body of the private `requirePayload` method to delegate to it, and delete the now-redundant bearer-token-decoding logic:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Services\AuthService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthMiddleware $authMiddleware,
        private readonly bool $secureCookie
    ) {
    }

    public function login(Request $request): Response
    {
        $session = $this->auth->login((string) ($request->json['email'] ?? ''), (string) ($request->json['password'] ?? ''));
        return $this->sessionResponse($session);
    }

    public function signup(Request $request): Response
    {
        $session = $this->auth->signup($request->json ?? []);
        return $this->sessionResponse($session, true);
    }

    public function refresh(Request $request): Response
    {
        $token = $request->cookies['refreshToken'] ?? '';
        $session = $this->auth->refresh($token);
        return $this->sessionResponse($session);
    }

    public function logout(Request $request): Response
    {
        $token = $request->cookies['refreshToken'] ?? '';
        if ($token !== '') {
            $this->auth->logout($token);
        }

        return new Response(
            ['status' => 'success', 'data' => null],
            200,
            ['Set-Cookie' => $this->clearCookieHeader()]
        );
    }

    public function me(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        return Response::success($this->auth->currentUser((int) $payload['sub']));
    }

    public function updateMe(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $email = (string) ($request->json['email'] ?? '');
        if ($email === '') {
            throw new ValidationException('email is required');
        }
        return Response::success($this->auth->updateEmail((int) $payload['sub'], $email));
    }

    public function changePassword(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->auth->changePassword((int) $payload['sub'], (string) ($request->json['password'] ?? ''));
        return Response::success(null);
    }

    private function sessionResponse(array $session, bool $includeUser = false): Response
    {
        $data = ['accessToken' => $session['accessToken']];
        if ($includeUser) {
            $data['user'] = $session['user'];
        }

        return new Response(
            ['status' => 'success', 'data' => $data],
            200,
            ['Set-Cookie' => $this->cookieHeader($session['refreshToken'])]
        );
    }

    private function cookieHeader(string $token): string
    {
        $secure = $this->secureCookie ? '; Secure' : '';
        return "refreshToken={$token}; Path=/; HttpOnly; SameSite=Lax{$secure}";
    }

    private function clearCookieHeader(): string
    {
        $secure = $this->secureCookie ? '; Secure' : '';
        return "refreshToken=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0{$secure}";
    }
}
```

- [ ] **Step 4: Update routes.php wiring**

In `src/routes.php`, change the `AuthController` construction line to pass an `AuthMiddleware` instance:

```php
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
```

```php
$authMiddleware = new AuthMiddleware($tokens);
$roleMiddleware = new RoleMiddleware();
$auth = new AuthController($authService, $authMiddleware, $config->bool('COOKIE_SECURE', false));
```

Leave the 7 existing auth route registrations unchanged — their handlers still call the same `$auth->method($request)` closures.

- [ ] **Step 5: Run full suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: PASS, all existing tests plus the 3 new `AuthMiddlewareTest` tests (21 total by end of this task, given 18 existing from Phase 1).

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/AuthController.php src/routes.php tests/Middleware/AuthMiddlewareTest.php
git commit -m "refactor: consolidate auth-payload lookup through AuthMiddleware"
```

---

### Task 2: Pagination Helper

**Files:**
- Create: `src/Core/Pagination.php`
- Test: `tests/Core/PaginationTest.php`

**Interfaces:**
- Consumes: `Request $request` (for query params).
- Produces: `Pagination::fromRequest(Request $request): Pagination` with public readonly `int $page`, `int $limit`, `int $offset`.
- Produces: `Pagination::meta(int $total): array` returning `['total' => int, 'page' => int, 'limit' => int, 'totalPages' => int]`.

- [ ] **Step 1: Write failing test**

Create `tests/Core/PaginationTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testDefaultsToPageOneLimitTen(): void
    {
        $request = new Request('GET', '/api/books/', [], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(1, $pagination->page);
        $this->assertSame(10, $pagination->limit);
        $this->assertSame(0, $pagination->offset);
    }

    public function testReadsPageAndLimitFromQuery(): void
    {
        $request = new Request('GET', '/api/books/', ['page' => '3', 'limit' => '20'], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(3, $pagination->page);
        $this->assertSame(20, $pagination->limit);
        $this->assertSame(40, $pagination->offset);
    }

    public function testClampsLimitToMaximumOfOneHundred(): void
    {
        $request = new Request('GET', '/api/books/', ['limit' => '500'], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(100, $pagination->limit);
    }

    public function testClampsPageToMinimumOfOne(): void
    {
        $request = new Request('GET', '/api/books/', ['page' => '0'], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(1, $pagination->page);
    }

    public function testMetaComputesTotalPages(): void
    {
        $pagination = Pagination::fromRequest(new Request('GET', '/api/books/', ['page' => '2', 'limit' => '20'], [], [], null));

        $meta = $pagination->meta(42);

        $this->assertSame(['total' => 42, 'page' => 2, 'limit' => 20, 'totalPages' => 3], $meta);
    }

    public function testMetaTotalPagesIsZeroWhenTotalIsZero(): void
    {
        $pagination = Pagination::fromRequest(new Request('GET', '/api/books/', [], [], [], null));

        $meta = $pagination->meta(0);

        $this->assertSame(0, $meta['totalPages']);
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Core/PaginationTest.php
```

Expected: FAIL with `Class "LibraTrack\Core\Pagination" not found`.

- [ ] **Step 3: Implement Pagination**

Create `src/Core/Pagination.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Pagination
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

    public function __construct(
        public readonly int $page,
        public readonly int $limit,
        public readonly int $offset
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = (int) ($request->query['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit === 0 ? self::DEFAULT_LIMIT : $limit));

        return new self($page, $limit, ($page - 1) * $limit);
    }

    public function meta(int $total): array
    {
        return [
            'total' => $total,
            'page' => $this->page,
            'limit' => $this->limit,
            'totalPages' => $total === 0 ? 0 : (int) ceil($total / $this->limit),
        ];
    }
}
```

- [ ] **Step 4: Run test**

Run:

```bash
vendor/bin/phpunit tests/Core/PaginationTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Core/Pagination.php tests/Core/PaginationTest.php
git commit -m "feat: add pagination helper"
```

---

### Task 3: Catalog Migration (Categories and Books)

**Files:**
- Create: `database/migrations/002_create_catalog_tables.php`

**Interfaces:**
- Consumes: `database/migrate.php` (Phase 1, unchanged runner — picks up any file matching `database/migrations/*.php`).
- Produces: `categories` and `books` tables.

- [ ] **Step 1: Write migration file**

Create `database/migrations/002_create_catalog_tables.php`:

```php
<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            title VARCHAR(500) NOT NULL,
            author VARCHAR(500) NOT NULL,
            isbn VARCHAR(20) NOT NULL UNIQUE,
            total_copies INT NOT NULL DEFAULT 1,
            available_copies INT NOT NULL DEFAULT 1,
            publisher VARCHAR(255) NULL,
            published_year INT NULL,
            cover_url VARCHAR(500) NULL,
            openlibrary_work_key VARCHAR(64) NULL,
            synopsis TEXT NULL,
            subjects JSON NULL,
            language_codes JSON NULL,
            edition_count INT UNSIGNED NOT NULL DEFAULT 0,
            rating_average FLOAT NULL,
            rating_count INT UNSIGNED NOT NULL DEFAULT 0,
            want_to_read_count INT UNSIGNED NOT NULL DEFAULT 0,
            currently_reading_count INT UNSIGNED NOT NULL DEFAULT 0,
            already_read_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_books_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
            INDEX idx_books_title (title(191)),
            INDEX idx_books_author (author(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS books',
        'DROP TABLE IF EXISTS categories',
    ],
];
```

Note: `category_id` uses `ON DELETE RESTRICT` (MySQL has no `PROTECT`; `RESTRICT` is the equivalent — the DB refuses the delete if rows reference it). The repository layer in Task 4 checks book count first and returns a clean 400 before ever hitting this constraint, so the constraint is a safety net, not the primary UX path.

- [ ] **Step 2: Run migration against local MySQL**

Run:

```bash
php database/migrate.php
```

Expected:

```text
Skipping 001_create_core_auth_tables.php
Applied 002_create_catalog_tables.php
```

- [ ] **Step 3: Verify rollback works**

Run:

```bash
php database/migrate.php down
php database/migrate.php
```

Expected: `Rolled back 002_create_catalog_tables.php` then `Rolled back 001_create_core_auth_tables.php` on the `down` run (reverse order), then both re-`Applied` on the `up` run. Re-run `php database/seed.php` afterward to restore demo data, since `down` drops the `users`/`members`/`settings` tables too.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/002_create_catalog_tables.php
git commit -m "feat: add catalog migration for categories and books"
```

---

### Task 4: Category Repository, Controller, and Routes

**Files:**
- Create: `src/Repositories/CategoryRepository.php`
- Create: `src/Controllers/CategoryController.php`
- Modify: `src/routes.php`
- Test: `tests/Feature/CategoryEndpointTest.php`

**Interfaces:**
- Consumes: `Pagination` (Task 2), `AuthMiddleware`/`RoleMiddleware` (Phase 1 + Task 1).
- Produces: `CategoryRepository::list(bool $onlyWithBooks, Pagination $pagination): array{rows: array, total: int}`.
- Produces: `CategoryRepository::find(int $id): ?array`.
- Produces: `CategoryRepository::findByName(string $name): ?array`.
- Produces: `CategoryRepository::create(string $name): int`.
- Produces: `CategoryRepository::update(int $id, string $name): void`.
- Produces: `CategoryRepository::countBooks(int $id): int`.
- Produces: `CategoryRepository::delete(int $id): void`.
- Produces: `GET/POST /api/categories/`, `GET/PATCH/PUT/DELETE /api/categories/{id}/`.

- [ ] **Step 1: Write failing feature test**

Create `tests/Feature/CategoryEndpointTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Feature/CategoryEndpointTest.php
```

Expected: FAIL (404s — routes and classes don't exist yet).

- [ ] **Step 3: Implement CategoryRepository**

Create `src/Repositories/CategoryRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(bool $onlyWithBooks, Pagination $pagination): array
    {
        $having = $onlyWithBooks ? 'HAVING COUNT(books.id) > 0' : '';

        $countSql = "SELECT COUNT(*) FROM (
            SELECT categories.id
            FROM categories
            LEFT JOIN books ON books.category_id = categories.id
            GROUP BY categories.id
            {$having}
        ) AS counted";
        $total = (int) $this->pdo->query($countSql)->fetchColumn();

        $sql = "SELECT categories.id, categories.name, COUNT(books.id) AS book_count
                FROM categories
                LEFT JOIN books ON books.category_id = categories.id
                GROUP BY categories.id, categories.name
                {$having}
                ORDER BY categories.name ASC
                LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT categories.id, categories.name, COUNT(books.id) AS book_count
             FROM categories
             LEFT JOIN books ON books.category_id = categories.id
             WHERE categories.id = ?
             GROUP BY categories.id, categories.name'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM categories WHERE name = ?');
        $statement->execute([$name]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function create(string $name): int
    {
        $statement = $this->pdo->prepare('INSERT INTO categories (name) VALUES (?)');
        $statement->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE categories SET name = ? WHERE id = ?');
        $statement->execute([$name, $id]);
    }

    public function countBooks(int $id): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM books WHERE category_id = ?');
        $statement->execute([$id]);
        return (int) $statement->fetchColumn();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM categories WHERE id = ?');
        $statement->execute([$id]);
    }
}
```

- [ ] **Step 4: Implement CategoryController**

Create `src/Controllers/CategoryController.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\CategoryRepository;

final class CategoryController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authMiddleware->authenticate($request);

        $onlyWithBooks = $this->parseBool($request->query['withBooks'] ?? $request->query['hasBooks'] ?? null);
        $pagination = Pagination::fromRequest($request);
        $result = $this->categories->list($onlyWithBooks, $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function store(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $name = trim((string) ($request->json['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('name is required');
        }
        if ($this->categories->findByName($name)) {
            throw new ValidationException('Category name already exists');
        }

        $id = $this->categories->create($name);
        $category = $this->categories->find($id);

        return Response::success($this->toFrontend($category), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $this->authMiddleware->authenticate($request);

        $category = $this->categories->find((int) $params['id']);
        if ($category === null) {
            throw new ValidationException('Category not found', 404);
        }

        return Response::success($this->toFrontend($category));
    }

    public function update(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $id = (int) $params['id'];
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new ValidationException('Category not found', 404);
        }

        $name = trim((string) ($request->json['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('name is required');
        }
        $existing = $this->categories->findByName($name);
        if ($existing && (int) $existing['id'] !== $id) {
            throw new ValidationException('Category name already exists');
        }

        $this->categories->update($id, $name);

        return Response::success($this->toFrontend($this->categories->find($id)));
    }

    public function destroy(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin']);

        $id = (int) $params['id'];
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new ValidationException('Category not found', 404);
        }
        if ($this->categories->countBooks($id) > 0) {
            throw new ValidationException('Cannot delete category with existing books');
        }

        $this->categories->delete($id);

        return new Response(['status' => 'success', 'data' => null], 204);
    }

    private function parseBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        return in_array(strtolower($value), ['true', '1', 'yes'], true);
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'bookCount' => (int) $row['book_count'],
        ];
    }
}
```

- [ ] **Step 5: Wire category routes**

Modify `src/routes.php`: add imports and construction, then register the 5 routes. Add near the other repository/controller construction lines:

```php
use LibraTrack\Controllers\CategoryController;
use LibraTrack\Repositories\CategoryRepository;
```

```php
$categoryController = new CategoryController(new CategoryRepository($pdo), $authMiddleware, $roleMiddleware);
```

Add before `return $router;`:

```php
$router->add('GET', '/api/categories/', fn (Request $request, array $params): Response => $categoryController->index($request));
$router->add('POST', '/api/categories/', fn (Request $request, array $params): Response => $categoryController->store($request));
$router->add('GET', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->show($request, $params));
$router->add('PATCH', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->update($request, $params));
$router->add('PUT', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->update($request, $params));
$router->add('DELETE', '/api/categories/{id}/', fn (Request $request, array $params): Response => $categoryController->destroy($request, $params));
```

- [ ] **Step 6: Run feature test**

Run:

```bash
php database/migrate.php
vendor/bin/phpunit tests/Feature/CategoryEndpointTest.php
```

Expected: PASS (6 tests).

- [ ] **Step 7: Run full suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: PASS, no regressions.

- [ ] **Step 8: Commit**

```bash
git add src/Repositories/CategoryRepository.php src/Controllers/CategoryController.php src/routes.php tests/Feature/CategoryEndpointTest.php
git commit -m "feat: add category CRUD endpoints"
```

---

### Task 5: Book Repository, Controller, and Routes

**Files:**
- Create: `src/Repositories/BookRepository.php`
- Create: `src/Controllers/BookController.php`
- Modify: `src/routes.php`
- Test: `tests/Feature/BookEndpointTest.php`

**Interfaces:**
- Consumes: `Pagination`, `CategoryRepository::find` (to validate `categoryId` on create), `AuthMiddleware`/`RoleMiddleware`.
- Produces: `BookRepository::search(array $filters, string $sort, Pagination $pagination): array{rows: array, total: int}` where `$filters` may contain `q`, `categoryId`, `available` (bool|null).
- Produces: `BookRepository::find(int $id): ?array`.
- Produces: `BookRepository::findByIsbn(string $isbn): ?array`.
- Produces: `BookRepository::create(array $data): int`.
- Produces: `BookRepository::update(int $id, array $data): void`.
- Produces: `BookRepository::delete(int $id): void`.
- Produces: `GET/POST /api/books/`, `GET/PATCH/PUT/DELETE /api/books/{id}/`.

- [ ] **Step 1: Write failing feature test**

Create `tests/Feature/BookEndpointTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Feature/BookEndpointTest.php
```

Expected: FAIL (404s — routes and classes don't exist yet).

- [ ] **Step 3: Implement BookRepository**

Create `src/Repositories/BookRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
use PDO;

final class BookRepository
{
    private const SORT_CLAUSES = [
        'rating' => 'rating_average DESC NULLS LAST, rating_count DESC, created_at DESC',
        'most_read' => 'already_read_count DESC, rating_count DESC, created_at DESC',
        'popular' => '(want_to_read_count + currently_reading_count + already_read_count) DESC, rating_count DESC, created_at DESC',
        'title' => 'title ASC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(array $filters, string $sort, Pagination $pagination): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $orderBy = self::SORT_CLAUSES[$sort] ?? 'created_at DESC';

        $countSql = "SELECT COUNT(*) FROM books {$where}";
        $countStatement = $this->pdo->prepare($countSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = "SELECT books.*, categories.name AS category_name
                FROM books
                JOIN categories ON categories.id = books.category_id
                {$where}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT books.*, categories.name AS category_name
             FROM books
             JOIN categories ON categories.id = books.category_id
             WHERE books.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findByIsbn(string $isbn): ?array
    {
        $statement = $this->pdo->prepare('SELECT id FROM books WHERE isbn = ?');
        $statement->execute([$isbn]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO books (
                category_id, title, author, isbn, total_copies, available_copies,
                publisher, published_year, cover_url, openlibrary_work_key, synopsis,
                subjects, language_codes, edition_count, rating_average, rating_count,
                want_to_read_count, currently_reading_count, already_read_count
            ) VALUES (
                :category_id, :title, :author, :isbn, :total_copies, :available_copies,
                :publisher, :published_year, :cover_url, :openlibrary_work_key, :synopsis,
                :subjects, :language_codes, :edition_count, :rating_average, :rating_count,
                :want_to_read_count, :currently_reading_count, :already_read_count
            )'
        );
        $statement->execute($this->toRow($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $row = $this->toRow($data);
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($row)));
        $row['id'] = $id;

        $statement = $this->pdo->prepare("UPDATE books SET {$assignments} WHERE id = :id");
        $statement->execute($row);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM books WHERE id = ?');
        $statement->execute([$id]);
    }

    private function toRow(array $data): array
    {
        return [
            'category_id' => $data['categoryId'],
            'title' => $data['title'],
            'author' => $data['author'],
            'isbn' => $data['isbn'],
            'total_copies' => $data['totalCopies'],
            'available_copies' => $data['availableCopies'],
            'publisher' => $data['publisher'] ?? null,
            'published_year' => $data['publishedYear'] ?? null,
            'cover_url' => $data['coverUrl'] ?? null,
            'openlibrary_work_key' => $data['openLibraryWorkKey'] ?? null,
            'synopsis' => $data['synopsis'] ?? null,
            'subjects' => json_encode($data['subjects'] ?? [], JSON_THROW_ON_ERROR),
            'language_codes' => json_encode($data['languageCodes'] ?? [], JSON_THROW_ON_ERROR),
            'edition_count' => $data['editionCount'] ?? 0,
            'rating_average' => $data['ratingAverage'] ?? null,
            'rating_count' => $data['ratingCount'] ?? 0,
            'want_to_read_count' => $data['wantToReadCount'] ?? 0,
            'currently_reading_count' => $data['currentlyReadingCount'] ?? 0,
            'already_read_count' => $data['alreadyReadCount'] ?? 0,
        ];
    }

    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['q'])) {
            $clauses[] = '(title LIKE :q OR author LIKE :q OR isbn LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['categoryId'])) {
            $clauses[] = 'category_id = :category_id';
            $params[':category_id'] = $filters['categoryId'];
        }
        if (array_key_exists('available', $filters) && $filters['available'] !== null) {
            $clauses[] = $filters['available'] ? 'available_copies > 0' : 'available_copies = 0';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
```

- [ ] **Step 4: Implement BookController**

Create `src/Controllers/BookController.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\CategoryRepository;

final class BookController
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authMiddleware->authenticate($request);

        $filters = [
            'q' => $request->query['q'] ?? $request->query['search'] ?? null,
            'categoryId' => isset($request->query['category']) ? (int) $request->query['category'] : null,
            'available' => $this->parseTriBool($request->query['available'] ?? null),
        ];
        $sort = (string) ($request->query['sort'] ?? '');
        $pagination = Pagination::fromRequest($request);

        $result = $this->books->search($filters, $sort, $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function store(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $data = $this->validate($request->json ?? []);
        if ($this->books->findByIsbn($data['isbn'])) {
            throw new ValidationException('ISBN already exists');
        }

        $id = $this->books->create($data);

        return Response::success($this->toFrontend($this->books->find($id)), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $this->authMiddleware->authenticate($request);

        $book = $this->books->find((int) $params['id']);
        if ($book === null) {
            throw new ValidationException('Book not found', 404);
        }

        return Response::success($this->toFrontend($book));
    }

    public function update(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $id = (int) $params['id'];
        $existing = $this->books->find($id);
        if ($existing === null) {
            throw new ValidationException('Book not found', 404);
        }

        $merged = array_merge($this->fromFrontendRow($existing), $request->json ?? []);
        $data = $this->validate($merged);

        $isbnOwner = $this->books->findByIsbn($data['isbn']);
        if ($isbnOwner && (int) $isbnOwner['id'] !== $id) {
            throw new ValidationException('ISBN already exists');
        }

        $this->books->update($id, $data);

        return Response::success($this->toFrontend($this->books->find($id)));
    }

    public function destroy(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin']);

        $id = (int) $params['id'];
        if ($this->books->find($id) === null) {
            throw new ValidationException('Book not found', 404);
        }

        $this->books->delete($id);

        return new Response(['status' => 'success', 'data' => null], 204);
    }

    private function validate(array $data): array
    {
        foreach (['title', 'author', 'isbn', 'categoryId'] as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("{$field} is required");
            }
        }
        if ($this->categories->find((int) $data['categoryId']) === null) {
            throw new ValidationException('categoryId does not reference an existing category');
        }

        $data['totalCopies'] = (int) ($data['totalCopies'] ?? 1);
        $data['availableCopies'] = (int) ($data['availableCopies'] ?? 1);

        return $data;
    }

    private function parseTriBool(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        $lower = strtolower($value);
        if (in_array($lower, ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array($lower, ['false', '0', 'no'], true)) {
            return false;
        }
        return null;
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'author' => $row['author'],
            'isbn' => $row['isbn'],
            'categoryId' => (int) $row['category_id'],
            'categoryName' => $row['category_name'],
            'totalCopies' => (int) $row['total_copies'],
            'availableCopies' => (int) $row['available_copies'],
            'publisher' => $row['publisher'],
            'publishedYear' => $row['published_year'] !== null ? (int) $row['published_year'] : null,
            'coverUrl' => $row['cover_url'],
            'openLibraryWorkKey' => $row['openlibrary_work_key'],
            'synopsis' => $row['synopsis'],
            'subjects' => json_decode($row['subjects'] ?? '[]', true, 512, JSON_THROW_ON_ERROR),
            'languageCodes' => json_decode($row['language_codes'] ?? '[]', true, 512, JSON_THROW_ON_ERROR),
            'editionCount' => (int) $row['edition_count'],
            'ratingAverage' => $row['rating_average'] !== null ? (float) $row['rating_average'] : null,
            'ratingCount' => (int) $row['rating_count'],
            'wantToReadCount' => (int) $row['want_to_read_count'],
            'currentlyReadingCount' => (int) $row['currently_reading_count'],
            'alreadyReadCount' => (int) $row['already_read_count'],
        ];
    }

    private function fromFrontendRow(array $row): array
    {
        return $this->toFrontend($row);
    }
}
```

- [ ] **Step 5: Wire book routes**

Modify `src/routes.php`: add imports and construction, then register the 5 routes.

```php
use LibraTrack\Controllers\BookController;
use LibraTrack\Repositories\BookRepository;
```

```php
$categoryRepository = new CategoryRepository($pdo);
$bookController = new BookController(new BookRepository($pdo), $categoryRepository, $authMiddleware, $roleMiddleware);
```

Note: reuse the same `$categoryRepository` instance for `$categoryController` above rather than constructing it twice.

Add before `return $router;`:

```php
$router->add('GET', '/api/books/', fn (Request $request, array $params): Response => $bookController->index($request));
$router->add('POST', '/api/books/', fn (Request $request, array $params): Response => $bookController->store($request));
$router->add('GET', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->show($request, $params));
$router->add('PATCH', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->update($request, $params));
$router->add('PUT', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->update($request, $params));
$router->add('DELETE', '/api/books/{id}/', fn (Request $request, array $params): Response => $bookController->destroy($request, $params));
```

- [ ] **Step 6: Run feature test**

Run:

```bash
vendor/bin/phpunit tests/Feature/BookEndpointTest.php
```

Expected: PASS (7 tests).

- [ ] **Step 7: Run full suite**

Run:

```bash
vendor/bin/phpunit
```

Expected: PASS, no regressions.

- [ ] **Step 8: Commit**

```bash
git add src/Repositories/BookRepository.php src/Controllers/BookController.php src/routes.php tests/Feature/BookEndpointTest.php
git commit -m "feat: add book CRUD endpoints with search, filter, sort, and pagination"
```

---

### Task 6: Open Library Normalizer (Pure Logic, No Network)

**Files:**
- Create: `src/Services/OpenLibraryNormalizer.php`
- Test: `tests/Services/OpenLibraryNormalizerTest.php`

**Interfaces:**
- Produces: `OpenLibraryNormalizer::chooseIsbn(array $isbns): ?string`.
- Produces: `OpenLibraryNormalizer::buildCoverUrl(?string $isbn, mixed $coverId): ?string`.
- Produces: `OpenLibraryNormalizer::normalize(array $doc, string $categoryName): ?array` returning a candidate shaped like the frontend book fields (`title`, `author`, `isbn`, `publisher`, `publishedYear`, `coverUrl`, `openLibraryWorkKey`, `subjects`, `languageCodes`, `editionCount`, `ratingAverage`, `ratingCount`, `wantToReadCount`, `currentlyReadingCount`, `alreadyReadCount`, `categoryName`), or `null` if `title`/`author`/`isbn` cannot be determined.
- Produces: `OpenLibraryNormalizer::enrichWithWorkDetails(array $candidate, array $work): array` — merges subjects and extracts synopsis from a fetched work-detail payload.

This mirrors `apps/books/openlibrary_importer.py`'s pure functions exactly (see design research), with no HTTP calls — fully unit-testable.

- [ ] **Step 1: Write failing test**

Create `tests/Services/OpenLibraryNormalizerTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Services\OpenLibraryNormalizer;
use PHPUnit\Framework\TestCase;

final class OpenLibraryNormalizerTest extends TestCase
{
    public function testChooseIsbnPrefersIsbn13OverIsbn10(): void
    {
        $isbn = OpenLibraryNormalizer::chooseIsbn(['0-13-235088-2', '978-0132350884']);

        $this->assertSame('9780132350884', $isbn);
    }

    public function testChooseIsbnFallsBackToIsbn10(): void
    {
        $isbn = OpenLibraryNormalizer::chooseIsbn(['0-13-235088-2']);

        $this->assertSame('0132350882', $isbn);
    }

    public function testChooseIsbnReturnsNullWhenNoneValid(): void
    {
        $this->assertNull(OpenLibraryNormalizer::chooseIsbn(['not-an-isbn']));
        $this->assertNull(OpenLibraryNormalizer::chooseIsbn([]));
    }

    public function testBuildCoverUrlPrefersIsbn(): void
    {
        $url = OpenLibraryNormalizer::buildCoverUrl('9780132350884', 12345);

        $this->assertSame('https://covers.openlibrary.org/b/isbn/9780132350884-L.jpg', $url);
    }

    public function testBuildCoverUrlFallsBackToCoverId(): void
    {
        $url = OpenLibraryNormalizer::buildCoverUrl(null, 12345);

        $this->assertSame('https://covers.openlibrary.org/b/id/12345-L.jpg', $url);
    }

    public function testBuildCoverUrlReturnsNullWhenNeitherAvailable(): void
    {
        $this->assertNull(OpenLibraryNormalizer::buildCoverUrl(null, null));
    }

    public function testNormalizeExtractsAllFields(): void
    {
        $doc = [
            'key' => '/works/OL17618370W',
            'title' => 'Clean Code',
            'author_name' => ['Robert C. Martin'],
            'isbn' => ['0132350882', '9780132350884'],
            'publisher' => ['Prentice Hall'],
            'first_publish_year' => 2008,
            'cover_i' => 12345,
            'subject' => ['Computer software', 'Agile software development'],
            'language' => ['eng', 'spa'],
            'edition_count' => 13,
            'ratings_average' => 4.46,
            'ratings_count' => 41,
            'want_to_read_count' => 823,
            'currently_reading_count' => 35,
            'already_read_count' => 61,
        ];

        $candidate = OpenLibraryNormalizer::normalize($doc, 'Technology');

        $this->assertNotNull($candidate);
        $this->assertSame('Clean Code', $candidate['title']);
        $this->assertSame('Robert C. Martin', $candidate['author']);
        $this->assertSame('9780132350884', $candidate['isbn']);
        $this->assertSame('Prentice Hall', $candidate['publisher']);
        $this->assertSame(2008, $candidate['publishedYear']);
        $this->assertSame('https://covers.openlibrary.org/b/isbn/9780132350884-L.jpg', $candidate['coverUrl']);
        $this->assertSame('/works/OL17618370W', $candidate['openLibraryWorkKey']);
        $this->assertSame(['Computer software', 'Agile software development'], $candidate['subjects']);
        $this->assertSame(['eng', 'spa'], $candidate['languageCodes']);
        $this->assertSame(13, $candidate['editionCount']);
        $this->assertSame(4.46, $candidate['ratingAverage']);
        $this->assertSame(41, $candidate['ratingCount']);
        $this->assertSame(823, $candidate['wantToReadCount']);
        $this->assertSame(35, $candidate['currentlyReadingCount']);
        $this->assertSame(61, $candidate['alreadyReadCount']);
        $this->assertSame('Technology', $candidate['categoryName']);
    }

    public function testNormalizeReturnsNullWhenTitleMissing(): void
    {
        $this->assertNull(OpenLibraryNormalizer::normalize(['author_name' => ['A'], 'isbn' => ['9780132350884']], 'Fiction'));
    }

    public function testNormalizeReturnsNullWhenAuthorMissing(): void
    {
        $this->assertNull(OpenLibraryNormalizer::normalize(['title' => 'T', 'isbn' => ['9780132350884']], 'Fiction'));
    }

    public function testNormalizeReturnsNullWhenIsbnMissing(): void
    {
        $this->assertNull(OpenLibraryNormalizer::normalize(['title' => 'T', 'author_name' => ['A']], 'Fiction'));
    }

    public function testEnrichMergesSubjectsWithCandidateFirst(): void
    {
        $candidate = ['subjects' => ['Computer software'], 'synopsis' => null];
        $work = ['subjects' => ['Software design', 'Computer software']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame(['Computer software', 'Software design'], $enriched['subjects']);
    }

    public function testEnrichUsesFirstSentenceWhenDescriptionMissing(): void
    {
        $candidate = ['subjects' => [], 'synopsis' => null];
        $work = ['first_sentence' => ['value' => 'A great opening line.']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame('A great opening line.', $enriched['synopsis']);
    }

    public function testEnrichIgnoresPhysicalDescriptionAndFallsBackToFirstSentence(): void
    {
        $candidate = ['subjects' => [], 'synopsis' => null];
        $work = ['description' => 'ix, 340 pages : 20 cm', 'first_sentence' => ['value' => 'Real synopsis.']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame('Real synopsis.', $enriched['synopsis']);
    }

    public function testEnrichUsesDescriptionValueWhenItIsNotPhysical(): void
    {
        $candidate = ['subjects' => [], 'synopsis' => null];
        $work = ['description' => ['value' => 'A book about writing clean code.']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame('A book about writing clean code.', $enriched['synopsis']);
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Services/OpenLibraryNormalizerTest.php
```

Expected: FAIL with `Class "LibraTrack\Services\OpenLibraryNormalizer" not found`.

- [ ] **Step 3: Implement OpenLibraryNormalizer**

Create `src/Services/OpenLibraryNormalizer.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Services;

final class OpenLibraryNormalizer
{
    private const MAX_TITLE_LENGTH = 500;
    private const MAX_AUTHOR_LENGTH = 500;
    private const MAX_PUBLISHER_LENGTH = 255;
    private const MAX_SYNOPSIS_LENGTH = 3000;
    private const MAX_SUBJECT_LENGTH = 80;
    private const MAX_SUBJECTS = 12;
    private const MAX_LANGUAGES = 8;

    public static function chooseIsbn(array $isbns): ?string
    {
        $normalized = array_filter(array_map(
            static fn (mixed $value): string => strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $value)),
            $isbns
        ));

        foreach ($normalized as $candidate) {
            if (preg_match('/^\d{13}$/', $candidate) === 1) {
                return $candidate;
            }
        }
        foreach ($normalized as $candidate) {
            if (preg_match('/^\d{9}[0-9X]$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    public static function buildCoverUrl(?string $isbn, mixed $coverId): ?string
    {
        if ($isbn !== null && $isbn !== '') {
            return "https://covers.openlibrary.org/b/isbn/{$isbn}-L.jpg";
        }
        if (is_int($coverId) || (is_string($coverId) && ctype_digit($coverId))) {
            return "https://covers.openlibrary.org/b/id/{$coverId}-L.jpg";
        }

        return null;
    }

    public static function normalize(array $doc, string $categoryName): ?array
    {
        $title = self::firstText($doc['title'] ?? null, self::MAX_TITLE_LENGTH);
        if ($title === null) {
            return null;
        }

        $authors = array_map(
            static fn (mixed $name): string => self::truncate(trim((string) $name), self::MAX_AUTHOR_LENGTH),
            array_filter((array) ($doc['author_name'] ?? []))
        );
        $author = self::truncate(implode(', ', $authors), self::MAX_AUTHOR_LENGTH);
        if ($author === '') {
            return null;
        }

        $isbn = self::chooseIsbn((array) ($doc['isbn'] ?? []));
        if ($isbn === null) {
            return null;
        }

        $publisher = self::firstText($doc['publisher'] ?? null, self::MAX_PUBLISHER_LENGTH);
        $publishedYear = is_int($doc['first_publish_year'] ?? null) ? $doc['first_publish_year'] : null;

        return [
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'publisher' => $publisher,
            'publishedYear' => $publishedYear,
            'coverUrl' => self::buildCoverUrl($isbn, $doc['cover_i'] ?? null),
            'openLibraryWorkKey' => self::normalizeWorkKey($doc['key'] ?? null),
            'synopsis' => null,
            'subjects' => self::cleanUniqueList((array) ($doc['subject'] ?? []), self::MAX_SUBJECTS, self::MAX_SUBJECT_LENGTH),
            'languageCodes' => self::cleanUniqueList((array) ($doc['language'] ?? []), self::MAX_LANGUAGES, 12),
            'editionCount' => self::positiveInt($doc['edition_count'] ?? null),
            'ratingAverage' => self::positiveFloat($doc['ratings_average'] ?? null),
            'ratingCount' => self::positiveInt($doc['ratings_count'] ?? null),
            'wantToReadCount' => self::positiveInt($doc['want_to_read_count'] ?? null),
            'currentlyReadingCount' => self::positiveInt($doc['currently_reading_count'] ?? null),
            'alreadyReadCount' => self::positiveInt($doc['already_read_count'] ?? null),
            'categoryName' => $categoryName,
        ];
    }

    public static function enrichWithWorkDetails(array $candidate, array $work): array
    {
        $candidate['subjects'] = self::mergeUniqueLists(
            $candidate['subjects'] ?? [],
            (array) ($work['subjects'] ?? []),
            self::MAX_SUBJECTS,
            self::MAX_SUBJECT_LENGTH
        );
        $candidate['synopsis'] = self::extractSynopsis($work) ?? $candidate['synopsis'] ?? null;

        return $candidate;
    }

    private static function extractSynopsis(array $work): ?string
    {
        $description = $work['description'] ?? null;
        $text = is_array($description) ? ($description['value'] ?? null) : $description;
        if (is_string($text) && trim($text) !== '' && !self::looksLikePhysicalDescription($text)) {
            return self::truncate(trim($text), self::MAX_SYNOPSIS_LENGTH);
        }

        $firstSentence = $work['first_sentence'] ?? null;
        $sentenceText = is_array($firstSentence) ? ($firstSentence['value'] ?? null) : $firstSentence;
        if (is_string($sentenceText) && trim($sentenceText) !== '') {
            return self::truncate(trim($sentenceText), self::MAX_SYNOPSIS_LENGTH);
        }

        $excerpts = (array) ($work['excerpts'] ?? []);
        foreach ($excerpts as $excerpt) {
            $excerptText = is_array($excerpt) ? ($excerpt['excerpt'] ?? null) : null;
            if (is_string($excerptText) && trim($excerptText) !== '') {
                return self::truncate(trim($excerptText), self::MAX_SYNOPSIS_LENGTH);
            }
        }

        return null;
    }

    private static function looksLikePhysicalDescription(string $text): bool
    {
        return preg_match('/^\s*[ivxlc]*,?\s*\d+\s*(pages?|p\.)/i', $text) === 1
            || preg_match('/\d+\s*cm\b/i', $text) === 1;
    }

    private static function normalizeWorkKey(mixed $key): ?string
    {
        if (!is_string($key) || $key === '') {
            return null;
        }
        if (str_starts_with($key, '/works/')) {
            return $key;
        }
        if (preg_match('/^OL\d+W$/', $key) === 1) {
            return "/works/{$key}";
        }

        return null;
    }

    private static function firstText(mixed $value, int $maxLength): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : self::truncate($trimmed, $maxLength);
    }

    private static function truncate(string $value, int $maxLength): string
    {
        return mb_substr($value, 0, $maxLength);
    }

    private static function cleanUniqueList(array $values, int $maxItems, int $maxLength): array
    {
        $seen = [];
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = self::truncate(trim($value), $maxLength);
            $key = strtolower($trimmed);
            if ($trimmed === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $trimmed;
            if (count($result) >= $maxItems) {
                break;
            }
        }

        return $result;
    }

    private static function mergeUniqueLists(array $first, array $second, int $maxItems, int $maxLength): array
    {
        return self::cleanUniqueList([...$first, ...$second], $maxItems, $maxLength);
    }

    private static function positiveInt(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private static function positiveFloat(mixed $value): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float >= 0 ? $float : null;
    }
}
```

- [ ] **Step 4: Run test**

Run:

```bash
vendor/bin/phpunit tests/Services/OpenLibraryNormalizerTest.php
```

Expected: PASS (14 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/OpenLibraryNormalizer.php tests/Services/OpenLibraryNormalizerTest.php
git commit -m "feat: add Open Library document normalizer"
```

---

### Task 7: Open Library HTTP Client

**Files:**
- Create: `src/Services/OpenLibraryClient.php`

**Interfaces:**
- Consumes: none beyond ext-curl (bundled with PHP 8.2+ standard builds).
- Produces: `OpenLibraryClient::__construct(int $timeoutSeconds, int $retries)`.
- Produces: `OpenLibraryClient::searchDocs(string $query, int $page, int $pageSize): array` — returns a list of doc arrays (only dict-shaped entries, matching the Django filter behavior), or `[]` on exhausted retries.
- Produces: `OpenLibraryClient::fetchWork(string $workKey): array` — returns the decoded work-detail payload, or `[]` on failure.

This task has no meaningful unit test (it is a thin network I/O wrapper) — its correctness is verified by the CLI script's manual smoke test in Task 8. Keep this class minimal and unmockable-need-free: the import service in Task 8 depends on the interface above, not on curl directly, so tests for Task 8's orchestration logic can substitute a stub implementing the same two methods without needing a mocking framework.

- [ ] **Step 1: Implement OpenLibraryClient**

Create `src/Services/OpenLibraryClient.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Services;

final class OpenLibraryClient
{
    private const SEARCH_URL = 'https://openlibrary.org/search.json';
    private const BASE_URL = 'https://openlibrary.org';
    private const USER_AGENT = 'LibraTrack Open Library importer';
    private const SEARCH_FIELDS = 'key,title,author_name,isbn,publisher,first_publish_year,cover_i,subject,language,edition_count,ratings_average,ratings_count,want_to_read_count,currently_reading_count,already_read_count';

    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $retries = 5
    ) {
    }

    public function searchDocs(string $query, int $page, int $pageSize): array
    {
        $url = self::SEARCH_URL . '?' . http_build_query([
            'q' => $query,
            'page' => $page,
            'limit' => $pageSize,
            'fields' => self::SEARCH_FIELDS,
        ]);

        $payload = $this->getJsonWithRetries($url);
        $docs = $payload['docs'] ?? [];

        return array_values(array_filter($docs, static fn (mixed $doc): bool => is_array($doc)));
    }

    public function fetchWork(string $workKey): array
    {
        $normalized = str_starts_with($workKey, '/works/') ? $workKey : "/works/{$workKey}";
        $url = self::BASE_URL . $normalized . '.json';

        $payload = $this->getJsonWithRetries($url);

        return is_array($payload) ? $payload : [];
    }

    private function getJsonWithRetries(string $url): array
    {
        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            $json = $this->get($url);
            if ($json !== null) {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }
            if ($attempt < $this->retries) {
                usleep((int) (min(0.75 * $attempt, 5.0) * 1_000_000));
            }
        }

        return [];
    }

    private function get(string $url): ?string
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ["User-Agent: " . self::USER_AGENT],
        ]);

        $body = curl_exec($handle);
        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '' || $statusCode >= 400) {
            return null;
        }

        return $body;
    }
}
```

- [ ] **Step 2: Verify it compiles and autoloads**

Run:

```bash
php -l src/Services/OpenLibraryClient.php
vendor/bin/phpunit
```

Expected: `No syntax errors detected`, and the full suite still passes (this class has no tests of its own, so this just confirms it doesn't break autoloading).

- [ ] **Step 3: Commit**

```bash
git add src/Services/OpenLibraryClient.php
git commit -m "feat: add Open Library HTTP client with curl and retry backoff"
```

---

### Task 8: Open Library Import CLI Script

**Files:**
- Create: `src/Services/OpenLibraryImportService.php`
- Create: `scripts/import_openlibrary_books.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `OpenLibraryNormalizer` (Task 6), `OpenLibraryClient` (Task 7, or any object with the same two public methods), `BookRepository`/`CategoryRepository` (Task 4/5).
- Produces: `OpenLibraryImportService::run(array $options): array{imported: int, duplicates: int, invalid: int, categoriesCreated: int, detailFailures: int}`.
- Produces: CLI script `scripts/import_openlibrary_books.php` with the same flags as the Django command: `--limit` (default 500), `--copies` (default 50), `--page-size` (default 50), `--timeout` (default 30), `--retries` (default 5), `--skip-work-details` (flag).

- [ ] **Step 1: Implement OpenLibraryImportService**

Create `src/Services/OpenLibraryImportService.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Services;

use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\CategoryRepository;

final class OpenLibraryImportService
{
    private const TOPIC_QUERIES = [
        ['Fiction', 'fiction'],
        ['Classics', 'classic literature'],
        ['Science', 'science'],
        ['Technology', 'technology'],
        ['Programming', 'computer programming'],
        ['Business', 'business'],
        ['History', 'history'],
        ['Biography', 'biography'],
        ['Education', 'education'],
        ['Health', 'health'],
        ['Children', 'children books'],
        ['Literature', 'literature'],
    ];

    public function __construct(
        private readonly OpenLibraryClient $client,
        private readonly CategoryRepository $categories,
        private readonly BookRepository $books
    ) {
    }

    public function run(array $options): array
    {
        $limit = $options['limit'];
        $copies = $options['copies'];
        $pageSize = $options['pageSize'];
        $skipWorkDetails = $options['skipWorkDetails'];

        $imported = 0;
        $duplicates = 0;
        $invalid = 0;
        $categoriesCreated = 0;
        $detailFailures = 0;

        foreach (self::TOPIC_QUERIES as [$categoryName, $query]) {
            if ($imported >= $limit) {
                break;
            }

            $category = $this->categories->findByName($categoryName);
            if ($category === null) {
                $this->categories->create($categoryName);
                $categoriesCreated++;
            }

            $page = 1;
            while ($imported < $limit) {
                $docs = $this->client->searchDocs($query, $page, $pageSize);
                if ($docs === []) {
                    break;
                }

                foreach ($docs as $doc) {
                    if ($imported >= $limit) {
                        break;
                    }

                    $candidate = OpenLibraryNormalizer::normalize($doc, $categoryName);
                    if ($candidate === null) {
                        $invalid++;
                        continue;
                    }
                    if ($this->books->findByIsbn($candidate['isbn']) !== null) {
                        $duplicates++;
                        continue;
                    }

                    if (!$skipWorkDetails && $candidate['openLibraryWorkKey'] !== null) {
                        $work = $this->client->fetchWork($candidate['openLibraryWorkKey']);
                        if ($work === []) {
                            $detailFailures++;
                        } else {
                            $candidate = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);
                        }
                    }

                    $categoryRow = $this->categories->findByName($categoryName);
                    $candidate['categoryId'] = (int) $categoryRow['id'];
                    $candidate['totalCopies'] = $copies;
                    $candidate['availableCopies'] = $copies;

                    $this->books->create($candidate);
                    $imported++;
                }

                $page++;
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'categoriesCreated' => $categoriesCreated,
            'detailFailures' => $detailFailures,
        ];
    }
}
```

- [ ] **Step 2: Implement CLI entry point**

Create `scripts/import_openlibrary_books.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\CategoryRepository;
use LibraTrack\Services\OpenLibraryClient;
use LibraTrack\Services\OpenLibraryImportService;

function parseIntOption(array $argv, string $name, int $default): int
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            $value = (int) substr($arg, strlen("--{$name}="));
            if ($value < 1) {
                fwrite(STDERR, "--{$name} must be greater than 0\n");
                exit(1);
            }
            return $value;
        }
    }

    return $default;
}

function hasFlag(array $argv, string $name): bool
{
    return in_array("--{$name}", $argv, true);
}

$limit = parseIntOption($argv, 'limit', 500);
$copies = parseIntOption($argv, 'copies', 50);
$pageSize = parseIntOption($argv, 'page-size', 50);
$timeout = parseIntOption($argv, 'timeout', 30);
$retries = parseIntOption($argv, 'retries', 5);
$skipWorkDetails = hasFlag($argv, 'skip-work-details');

$root = dirname(__DIR__);
$config = Config::fromProjectRoot($root);
$pdo = Database::fromConfig($config)->pdo();

$service = new OpenLibraryImportService(
    new OpenLibraryClient($timeout, $retries),
    new CategoryRepository($pdo),
    new BookRepository($pdo)
);

$result = $service->run([
    'limit' => $limit,
    'copies' => $copies,
    'pageSize' => $pageSize,
    'skipWorkDetails' => $skipWorkDetails,
]);

echo "Imported: {$result['imported']}\n";
echo "Skipped duplicates: {$result['duplicates']}\n";
echo "Skipped invalid: {$result['invalid']}\n";
echo "Categories created: {$result['categoriesCreated']}\n";
echo "Work detail failures: {$result['detailFailures']}\n";
```

- [ ] **Step 3: Verify CLI argument validation**

Run:

```bash
php scripts/import_openlibrary_books.php --limit=0
```

Expected: prints `--limit must be greater than 0` to stderr and exits with status 1.

- [ ] **Step 4: Manual smoke test against local MySQL and the real Open Library API**

Run:

```bash
php scripts/import_openlibrary_books.php --limit=5 --copies=2 --page-size=5
```

Expected: prints the 5-line summary with `Imported: 5` (network permitting — if the sandbox has no outbound network access, run this step in an environment that does, and note the skip in the task report). Verify with:

```bash
php -r "
require 'vendor/autoload.php';
\$pdo = (new LibraTrack\Core\Database('mysql:host=127.0.0.1;port=3306;dbname=libratrack_php;charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: ''))->pdo();
echo \$pdo->query('SELECT COUNT(*) FROM books')->fetchColumn() . \" books\n\";
"
```

Expected: book count increased by 5 (or fewer, if duplicates were skipped across repeated runs).

- [ ] **Step 5: Update README**

In `README.md`, add an Open Library import section (alongside the existing "Database Commands" table) documenting the command and its flags:

```markdown
## Open Library Import

```bash
php scripts/import_openlibrary_books.php --limit=500 --copies=50
```

Reliability options:

```bash
php scripts/import_openlibrary_books.php --limit=500 --copies=50 --skip-work-details --timeout=60 --retries=6 --page-size=25
```

| Flag | Default | Purpose |
|---|---|---|
| `--limit` | 500 | Total books to import across all topics |
| `--copies` | 50 | Copies created per imported book |
| `--page-size` | 50 | Results per Open Library API page |
| `--timeout` | 30 | HTTP timeout in seconds |
| `--retries` | 5 | Retry attempts per page fetch |
| `--skip-work-details` | off | Skip per-work synopsis/subject enrichment for a faster import |
```

- [ ] **Step 6: Commit**

```bash
git add src/Services/OpenLibraryImportService.php scripts/import_openlibrary_books.php README.md
git commit -m "feat: add Open Library catalog import CLI"
```

---

## Phase 2 Completion Checklist

- [ ] `vendor/bin/phpunit` passes (all Phase 1 + Phase 2 tests).
- [ ] `php database/migrate.php` creates `categories` and `books` tables.
- [ ] `GET /api/categories/` and `GET /api/books/` require authentication (401 without a token).
- [ ] `POST`/`PATCH`/`PUT` on categories and books require admin or librarian (403 for members).
- [ ] `DELETE` on categories and books requires admin (403 for librarian).
- [ ] Book search (`q`), category filter, `available` filter, and all four `sort` values work.
- [ ] Category `withBooks`/`hasBooks` filter works, `bookCount` is accurate.
- [ ] Duplicate ISBN and duplicate category name return 400, not a raw DB error.
- [ ] Deleting a category with books returns 400, not a raw DB error.
- [ ] `scripts/import_openlibrary_books.php --limit=500 --copies=50` runs end-to-end against a real network and MySQL.
- [ ] `AuthController` no longer duplicates `AuthMiddleware`'s token-decoding logic.

## Known Deferred Work

- Members, transactions, reservations, fines, notifications, reports (Phases 3-5 per the design doc).
- Final Django file removal.
- Frontend browser smoke against the full PHP API.

These are deferred to later phase plans by the approved phased strategy in `docs/superpowers/specs/2026-07-13-php-backend-rewrite-design.md`.

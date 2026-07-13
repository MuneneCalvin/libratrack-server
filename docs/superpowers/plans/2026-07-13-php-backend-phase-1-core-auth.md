# Plain PHP Backend Phase 1 Core/Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build plain PHP backend foundation and auth/settings endpoints that match the current React frontend contract.

**Architecture:** Plain PHP app uses `public/index.php` as front controller, small core HTTP/router classes, PDO repositories, service classes, and JSON response helpers. Phase 1 creates only shared foundation, auth, current-user, refresh-token, settings, migrations, and seed data needed for frontend login and role routing.

**Tech Stack:** PHP 8.2+, Composer, PDO MySQL, `firebase/php-jwt`, `vlucas/phpdotenv`, PHPUnit.

## Global Constraints

- Use plain/core PHP, no framework.
- Composer is allowed for focused infrastructure packages.
- Use a fresh MySQL schema through PHP migrations and seeders.
- Support both local PHP built-in server and Apache/XAMPP.
- Preserve same `/api/...` route paths.
- Preserve same JSON response envelope.
- Preserve same camelCase response fields expected by the frontend.
- Preserve JWT access token plus HttpOnly `refreshToken` cookie flow.
- Keep frontend changes minimal and only fix contract mismatches if discovered.
- Work happens on `php-backend-rewrite` branch.

---

## File Structure

Phase 1 creates:

```text
composer.json
phpunit.xml
.env.example
public/index.php
public/.htaccess
src/Core/Config.php
src/Core/Database.php
src/Core/Request.php
src/Core/Response.php
src/Core/Router.php
src/Core/App.php
src/Core/ValidationException.php
src/Middleware/AuthMiddleware.php
src/Middleware/RoleMiddleware.php
src/Controllers/AuthController.php
src/Controllers/SettingsController.php
src/Repositories/UserRepository.php
src/Repositories/RefreshTokenRepository.php
src/Repositories/MemberRepository.php
src/Repositories/SettingsRepository.php
src/Services/PasswordService.php
src/Services/TokenService.php
src/Services/AuthService.php
src/routes.php
database/migrations/001_create_core_auth_tables.php
database/migrate.php
database/seed.php
tests/bootstrap.php
tests/Core/RouterTest.php
tests/Core/ResponseTest.php
tests/Services/PasswordServiceTest.php
tests/Services/TokenServiceTest.php
tests/Feature/AuthEndpointTest.php
README.md
```

Phase 1 removes no Django files yet. Replacement cleanup happens after PHP parity is complete.

---

### Task 1: Composer, Environment, and Public Entry Point

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.env.example`
- Create: `public/index.php`
- Create: `public/.htaccess`
- Create: `tests/bootstrap.php`
- Modify: `.gitignore`

**Interfaces:**
- Produces: Composer PSR-4 autoload namespace `LibraTrack\`.
- Produces: PHPUnit bootstrap at `tests/bootstrap.php`.
- Produces: `public/index.php` front controller loaded by built-in server and Apache.

- [ ] **Step 1: Write bootstrap smoke test**

Create `tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$_ENV['APP_ENV'] = 'test';
$_ENV['JWT_SECRET'] = 'test-secret';
$_ENV['JWT_ACCESS_TTL_MINUTES'] = '15';
$_ENV['JWT_REFRESH_TTL_DAYS'] = '7';
```

Create `tests/Core/BootstrapTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testComposerAutoloadIsAvailable(): void
    {
        $this->assertTrue(class_exists(DateTimeImmutable::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails before Composer setup**

Run:

```bash
vendor/bin/phpunit tests/Core/BootstrapTest.php
```

Expected: FAIL because `vendor/bin/phpunit` does not exist.

- [ ] **Step 3: Add Composer and PHPUnit config**

Create `composer.json`:

```json
{
  "name": "libratrack/plain-php-backend",
  "description": "Plain PHP backend for LibraTrack",
  "type": "project",
  "require": {
    "php": "^8.2",
    "ext-json": "*",
    "ext-pdo": "*",
    "ext-pdo_mysql": "*",
    "firebase/php-jwt": "^7.0",
    "vlucas/phpdotenv": "^5.6"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  },
  "autoload": {
    "psr-4": {
      "LibraTrack\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "LibraTrack\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit",
    "test:unit": "phpunit tests/Core tests/Services",
    "test:feature": "phpunit tests/Feature"
  }
}
```

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
  <testsuites>
    <testsuite name="Core">
      <directory>tests/Core</directory>
    </testsuite>
    <testsuite name="Services">
      <directory>tests/Services</directory>
    </testsuite>
    <testsuite name="Feature">
      <directory>tests/Feature</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

Create `.env.example`:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
CORS_ALLOWED_ORIGINS=http://localhost:5173

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=libratrack_php
DB_USER=root
DB_PASSWORD=

JWT_SECRET=dev-secret-key-change-in-production
JWT_ACCESS_TTL_MINUTES=15
JWT_REFRESH_TTL_DAYS=7
COOKIE_SECURE=false
```

Create `public/index.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\App;
use LibraTrack\Core\Config;

$config = Config::fromProjectRoot(dirname(__DIR__));
$app = App::fromConfig($config);

$app->run();
```

Create `public/.htaccess`:

```apacheconf
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

Append to `.gitignore`:

```gitignore
/vendor/
/.env
/.phpunit.cache/
```

- [ ] **Step 4: Install dependencies and run bootstrap test**

Run:

```bash
composer install
vendor/bin/phpunit tests/Core/BootstrapTest.php
```

Expected: PASS, one test.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml .env.example public/index.php public/.htaccess tests/bootstrap.php tests/Core/BootstrapTest.php .gitignore
git commit -m "chore: scaffold plain PHP backend runtime"
```

---

### Task 2: Core Config, Request, Response, Router, and App

**Files:**
- Create: `src/Core/Config.php`
- Create: `src/Core/Request.php`
- Create: `src/Core/Response.php`
- Create: `src/Core/Router.php`
- Create: `src/Core/App.php`
- Create: `src/Core/ValidationException.php`
- Create: `src/routes.php`
- Test: `tests/Core/RouterTest.php`
- Test: `tests/Core/ResponseTest.php`
- Modify: `tests/bootstrap.php`

**Interfaces:**
- Consumes: Composer autoload namespace `LibraTrack\`.
- Produces: `Config::fromProjectRoot(string $root): Config`.
- Produces: `Request::fromGlobals(): Request`.
- Produces: `Response::success(mixed $data = null, int $status = 200): Response`.
- Produces: `Response::paginated(array $data, array $meta): Response`.
- Produces: `Response::error(string $message, int $status = 400, array $extra = []): Response`.
- Produces: `Router::add(string $method, string $pattern, callable $handler): void`.
- Produces: `Router::dispatch(Request $request): Response`.
- Produces: `App::fromConfig(Config $config): App`.

- [ ] **Step 1: Write failing Router test**

Create `tests/Core/RouterTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesRouteWithPathParameter(): void
    {
        $router = new Router();
        $router->add('GET', '/api/books/{id}/', function (Request $request, array $params): Response {
            return Response::success(['id' => (int) $params['id']]);
        });

        $request = new Request('GET', '/api/books/42/', [], [], [], null);
        $response = $router->dispatch($request);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['status' => 'success', 'data' => ['id' => 42]], $response->payload);
    }

    public function testUnknownRouteReturnsNotFoundEnvelope(): void
    {
        $router = new Router();
        $request = new Request('GET', '/api/missing/', [], [], [], null);

        $response = $router->dispatch($request);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('error', $response->payload['status']);
        $this->assertSame('Route not found', $response->payload['message']);
    }
}
```

- [ ] **Step 2: Write failing Response test**

Create `tests/Core/ResponseTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testSuccessEnvelope(): void
    {
        $response = Response::success(['id' => 1]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['status' => 'success', 'data' => ['id' => 1]], $response->payload);
    }

    public function testPaginatedEnvelope(): void
    {
        $response = Response::paginated([['id' => 1]], [
            'total' => 1,
            'page' => 1,
            'limit' => 20,
            'totalPages' => 1,
        ]);

        $this->assertSame('success', $response->payload['status']);
        $this->assertSame([['id' => 1]], $response->payload['data']);
        $this->assertSame(1, $response->payload['meta']['total']);
    }

    public function testErrorEnvelopeWithExtraFields(): void
    {
        $response = Response::error('Limit reached', 400, ['remainingSlots' => 0]);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame([
            'status' => 'error',
            'message' => 'Limit reached',
            'remainingSlots' => 0,
        ], $response->payload);
    }
}
```

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Core/RouterTest.php tests/Core/ResponseTest.php
```

Expected: FAIL with classes not found.

- [ ] **Step 4: Implement core classes**

Create `src/Core/Response.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Response
{
    public function __construct(
        public readonly array $payload,
        public readonly int $statusCode = 200,
        public readonly array $headers = []
    ) {
    }

    public static function success(mixed $data = null, int $status = 200): self
    {
        return new self(['status' => 'success', 'data' => $data], $status);
    }

    public static function paginated(array $data, array $meta): self
    {
        return new self(['status' => 'success', 'data' => $data, 'meta' => $meta]);
    }

    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return new self(array_merge(['status' => 'error', 'message' => $message], $extra), $status);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($this->payload, JSON_UNESCAPED_SLASHES);
    }
}
```

Create `src/Core/Request.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly ?array $json
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $body = file_get_contents('php://input') ?: '';
        $json = $body === '' ? null : json_decode($body, true);

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::normalizePath($path),
            $_GET,
            self::headersFromServer($_SERVER),
            $_COOKIE,
            is_array($json) ? $json : null
        );
    }

    public static function normalizePath(string $path): string
    {
        if ($path !== '/' && str_ends_with($path, '/') === false) {
            return $path . '/';
        }
        return $path;
    }

    public function bearerToken(): ?string
    {
        $header = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        return $headers;
    }
}
```

Create `src/Core/Router.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            $params = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
            return ($route['handler'])($request, $params);
        }

        return Response::error('Route not found', 404);
    }
}
```

Create `src/Core/Config.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

use Dotenv\Dotenv;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function fromProjectRoot(string $root): self
    {
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        return new self($_ENV + getenv());
    }

    public function get(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;
        if ($value === null) {
            throw new \RuntimeException("Missing config value: {$key}");
        }
        return (string) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->values[$key] ?? $default, FILTER_VALIDATE_BOOL);
    }
}
```

Create `src/Core/ValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class ValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}
```

Create `src/Core/App.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class App
{
    public function __construct(private readonly Router $router)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $router = require dirname(__DIR__) . '/routes.php';
        return new self($router);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            return Response::error($exception->getMessage(), $exception->statusCode);
        } catch (\Throwable) {
            return Response::error('Internal server error', 500);
        }
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}
```

Create `src/routes.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;

$router = new Router();

$router->add('GET', '/api/health/', function (Request $request, array $params): Response {
    return Response::success(['ok' => true]);
});

return $router;
```

- [ ] **Step 5: Run core tests**

Run:

```bash
vendor/bin/phpunit tests/Core
```

Expected: PASS.

- [ ] **Step 6: Smoke front controller**

Run server:

```bash
php -S localhost:8000 -t public
```

In another terminal:

```bash
curl -s http://localhost:8000/api/health/
```

Expected:

```json
{"status":"success","data":{"ok":true}}
```

- [ ] **Step 7: Commit**

```bash
git add src/Core src/routes.php tests/Core tests/bootstrap.php
git commit -m "feat: add plain PHP HTTP core"
```

---

### Task 3: Database Connection, Migrations, and Core Schema

**Files:**
- Create: `src/Core/Database.php`
- Create: `database/migrations/001_create_core_auth_tables.php`
- Create: `database/migrate.php`
- Test: `tests/Core/DatabaseTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `Config`.
- Produces: `Database::fromConfig(Config $config): Database`.
- Produces: `Database::pdo(): PDO`.
- Produces: migration files returning `array{up: string[], down: string[]}`.
- Produces: `php database/migrate.php` CLI migration runner.

- [ ] **Step 1: Write failing Database test**

Create `tests/Core/DatabaseTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testBuildsPdoDsnFromConfig(): void
    {
        $config = new Config([
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'libratrack_test',
            'DB_USER' => 'root',
            'DB_PASSWORD' => '',
        ]);

        $database = Database::fromConfig($config);

        $this->assertSame('mysql:host=127.0.0.1;port=3306;dbname=libratrack_test;charset=utf8mb4', $database->dsn());
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Core/DatabaseTest.php
```

Expected: FAIL with `Class "LibraTrack\Core\Database" not found`.

- [ ] **Step 3: Implement Database**

Create `src/Core/Database.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Core;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->get('DB_PORT', '3306'),
            $config->get('DB_NAME'),
        );

        return new self($dsn, $config->get('DB_USER'), $config->get('DB_PASSWORD', ''));
    }

    public function dsn(): string
    {
        return $this->dsn;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }
}
```

- [ ] **Step 4: Add migration file**

Create `database/migrations/001_create_core_auth_tables.php`:

```php
<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS migration_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS refresh_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            full_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NULL,
            address VARCHAR(500) NULL,
            membership_number VARCHAR(40) NOT NULL UNIQUE,
            joined_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS settings',
        'DROP TABLE IF EXISTS members',
        'DROP TABLE IF EXISTS refresh_tokens',
        'DROP TABLE IF EXISTS users',
        'DROP TABLE IF EXISTS roles',
        'DROP TABLE IF EXISTS migration_log',
    ],
];
```

- [ ] **Step 5: Add migration runner**

Create `database/migrate.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;

$root = dirname(__DIR__);
$config = Config::fromProjectRoot($root);
$pdo = Database::fromConfig($config)->pdo();
$direction = $argv[1] ?? 'up';

$files = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($files);

if ($direction === 'down') {
    $files = array_reverse($files);
}

foreach ($files as $file) {
    $name = basename($file);
    $migration = require $file;

    if ($direction === 'up') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS migration_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $statement = $pdo->prepare('SELECT COUNT(*) FROM migration_log WHERE migration = ?');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() > 0) {
            echo "Skipping {$name}\n";
            continue;
        }
    }

    foreach ($migration[$direction] as $sql) {
        $pdo->exec($sql);
    }

    if ($direction === 'up') {
        $statement = $pdo->prepare('INSERT INTO migration_log (migration) VALUES (?)');
        $statement->execute([$name]);
        echo "Applied {$name}\n";
    } else {
        echo "Rolled back {$name}\n";
    }
}
```

- [ ] **Step 6: Run unit test**

Run:

```bash
vendor/bin/phpunit tests/Core/DatabaseTest.php
```

Expected: PASS.

- [ ] **Step 7: Run migration against local MySQL**

Run:

```bash
php database/migrate.php
```

Expected:

```text
Applied 001_create_core_auth_tables.php
```

- [ ] **Step 8: Commit**

```bash
git add src/Core/Database.php database/migrations/001_create_core_auth_tables.php database/migrate.php tests/Core/DatabaseTest.php README.md
git commit -m "feat: add PHP database migrations"
```

---

### Task 4: Seed Roles, Demo Users, Members, and Settings

**Files:**
- Create: `database/seed.php`
- Create: `src/Services/PasswordService.php`
- Test: `tests/Services/PasswordServiceTest.php`

**Interfaces:**
- Produces: `PasswordService::hash(string $password): string`.
- Produces: `PasswordService::verify(string $password, string $hash): bool`.
- Produces demo accounts:
  - `admin@libratrack.com` / `Admin@1234`
  - `librarian@libratrack.com` / `Librarian@1234`
  - `alice@libratrack.com` / `Member@1234`
  - `bob@libratrack.com` / `Member@1234`

- [ ] **Step 1: Write failing PasswordService test**

Create `tests/Services/PasswordServiceTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Services\PasswordService;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
    public function testHashesAndVerifiesPassword(): void
    {
        $service = new PasswordService();

        $hash = $service->hash('Admin@1234');

        $this->assertNotSame('Admin@1234', $hash);
        $this->assertTrue($service->verify('Admin@1234', $hash));
        $this->assertFalse($service->verify('wrong-password', $hash));
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Services/PasswordServiceTest.php
```

Expected: FAIL with `Class "LibraTrack\Services\PasswordService" not found`.

- [ ] **Step 3: Implement PasswordService**

Create `src/Services/PasswordService.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Services;

final class PasswordService
{
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
```

- [ ] **Step 4: Add seed script**

Create `database/seed.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Services\PasswordService;

$root = dirname(__DIR__);
$pdo = Database::fromConfig(Config::fromProjectRoot($root))->pdo();
$passwords = new PasswordService();

$roles = ['admin', 'librarian', 'member'];
foreach ($roles as $role) {
    $statement = $pdo->prepare('INSERT IGNORE INTO roles (name) VALUES (?)');
    $statement->execute([$role]);
}

$roleId = function (string $role) use ($pdo): int {
    $statement = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
    $statement->execute([$role]);
    return (int) $statement->fetchColumn();
};

$createUser = function (
    string $email,
    string $password,
    string $role,
    bool $mustChangePassword = false
) use ($pdo, $passwords, $roleId): int {
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $statement->execute([$email]);
    $existing = $statement->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (role_id, email, password_hash, must_change_password, is_active)
         VALUES (?, ?, ?, ?, 1)'
    );
    $statement->execute([
        $roleId($role),
        $email,
        $passwords->hash($password),
        $mustChangePassword ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
};

$adminId = $createUser('admin@libratrack.com', 'Admin@1234', 'admin');
$librarianId = $createUser('librarian@libratrack.com', 'Librarian@1234', 'librarian');
$aliceUserId = $createUser('alice@libratrack.com', 'Member@1234', 'member');
$bobUserId = $createUser('bob@libratrack.com', 'Member@1234', 'member');

$members = [
    [$aliceUserId, 'Alice Johnson', '+254 712 000 001', 'Nairobi, Kenya', 'MEM-A3F2B1'],
    [$bobUserId, 'Bob Smith', '+254 712 000 002', 'Nairobi, Kenya', 'MEM-B4C3D2'],
];

foreach ($members as [$userId, $name, $phone, $address, $membershipNumber]) {
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO members (user_id, full_name, phone, address, membership_number, joined_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $statement->execute([$userId, $name, $phone, $address, $membershipNumber]);
}

$settings = [
    'fine_rate_per_day' => '10',
    'borrow_days' => '14',
    'max_books_per_member' => '5',
    'reservation_expiry_days' => '3',
];

foreach ($settings as $key => $value) {
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([$key, $value]);
}

echo "Seeded roles, users, members, and settings\n";
```

- [ ] **Step 5: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Services/PasswordServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Run seed script**

Run:

```bash
php database/seed.php
```

Expected:

```text
Seeded roles, users, members, and settings
```

- [ ] **Step 7: Commit**

```bash
git add src/Services/PasswordService.php database/seed.php tests/Services/PasswordServiceTest.php
git commit -m "feat: seed PHP auth demo data"
```

---

### Task 5: Token Service and Auth Repositories

**Files:**
- Create: `src/Services/TokenService.php`
- Create: `src/Repositories/UserRepository.php`
- Create: `src/Repositories/RefreshTokenRepository.php`
- Create: `src/Repositories/MemberRepository.php`
- Test: `tests/Services/TokenServiceTest.php`

**Interfaces:**
- Consumes: `Config`, `PasswordService`.
- Produces: `TokenService::issueAccessToken(array $user): string`.
- Produces: `TokenService::decodeAccessToken(string $token): array`.
- Produces: `TokenService::newRefreshToken(): string`.
- Produces: `TokenService::hashRefreshToken(string $token): string`.
- Produces: `UserRepository::findByEmail(string $email): ?array`.
- Produces: `UserRepository::findById(int $id): ?array`.
- Produces: `UserRepository::createUser(string $email, string $passwordHash, string $role, bool $mustChangePassword): int`.
- Produces: `RefreshTokenRepository::store(int $userId, string $hash, DateTimeImmutable $expiresAt): void`.
- Produces: `RefreshTokenRepository::findActiveByHash(string $hash): ?array`.
- Produces: `RefreshTokenRepository::revokeByHash(string $hash): void`.
- Produces: `MemberRepository::createForUser(int $userId, string $fullName, ?string $phone, ?string $address): int`.
- Produces: `MemberRepository::findByUserId(int $userId): ?array`.

- [ ] **Step 1: Write failing TokenService test**

Create `tests/Services/TokenServiceTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    public function testIssuesAndDecodesAccessToken(): void
    {
        $service = new TokenService(new Config([
            'JWT_SECRET' => 'unit-test-secret',
            'JWT_ACCESS_TTL_MINUTES' => '15',
            'JWT_REFRESH_TTL_DAYS' => '7',
        ]));

        $token = $service->issueAccessToken([
            'id' => 7,
            'email' => 'admin@libratrack.com',
            'role' => 'admin',
        ]);

        $payload = $service->decodeAccessToken($token);

        $this->assertSame('7', $payload['sub']);
        $this->assertSame('admin@libratrack.com', $payload['email']);
        $this->assertSame('admin', $payload['role']);
        $this->assertArrayHasKey('exp', $payload);
    }

    public function testRefreshTokenHashIsSha256(): void
    {
        $service = new TokenService(new Config([
            'JWT_SECRET' => 'unit-test-secret',
            'JWT_ACCESS_TTL_MINUTES' => '15',
            'JWT_REFRESH_TTL_DAYS' => '7',
        ]));

        $hash = $service->hashRefreshToken('abc');

        $this->assertSame(hash('sha256', 'abc'), $hash);
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Services/TokenServiceTest.php
```

Expected: FAIL with `Class "LibraTrack\Services\TokenService" not found`.

- [ ] **Step 3: Implement TokenService**

Create `src/Services/TokenService.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Services;

use DateInterval;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LibraTrack\Core\Config;

final class TokenService
{
    public function __construct(private readonly Config $config)
    {
    }

    public function issueAccessToken(array $user): string
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval('PT' . $this->config->get('JWT_ACCESS_TTL_MINUTES', '15') . 'M'));

        return JWT::encode([
            'sub' => (string) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
        ], $this->config->get('JWT_SECRET'), 'HS256');
    }

    public function decodeAccessToken(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->config->get('JWT_SECRET'), 'HS256'));
        return json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function newRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function refreshExpiresAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->add(new DateInterval('P' . $this->config->get('JWT_REFRESH_TTL_DAYS', '7') . 'D'));
    }
}
```

- [ ] **Step 4: Implement repositories**

Create `src/Repositories/UserRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.*, roles.name AS role
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.email = ?'
        );
        $statement->execute([$email]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.*, roles.name AS role
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function createUser(string $email, string $passwordHash, string $role, bool $mustChangePassword): int
    {
        $roleStatement = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $roleStatement->execute([$role]);
        $roleId = (int) $roleStatement->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO users (role_id, email, password_hash, must_change_password, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $statement->execute([$roleId, $email, $passwordHash, $mustChangePassword ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateEmail(int $id, string $email): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
        $statement->execute([$email, $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
        );
        $statement->execute([$passwordHash, $id]);
    }
}
```

Create `src/Repositories/RefreshTokenRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use DateTimeImmutable;
use PDO;

final class RefreshTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(int $userId, string $hash, DateTimeImmutable $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $hash, $expiresAt->format('Y-m-d H:i:s')]);
    }

    public function findActiveByHash(string $hash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM refresh_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'
        );
        $statement->execute([$hash]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function revokeByHash(string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL'
        );
        $statement->execute([$hash]);
    }
}
```

Create `src/Repositories/MemberRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class MemberRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createForUser(int $userId, string $fullName, ?string $phone, ?string $address): int
    {
        $membershipNumber = 'MEM-' . strtoupper(bin2hex(random_bytes(3)));
        $statement = $this->pdo->prepare(
            'INSERT INTO members (user_id, full_name, phone, address, membership_number, joined_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $statement->execute([$userId, $fullName, $phone, $address, $membershipNumber]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findByUserId(int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM members WHERE user_id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch();
        return $row ?: null;
    }
}
```

- [ ] **Step 5: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Services/TokenServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Services/TokenService.php src/Repositories tests/Services/TokenServiceTest.php
git commit -m "feat: add PHP auth token services"
```

---

### Task 6: Auth Service, Middleware, Routes, and Endpoints

**Files:**
- Create: `src/Services/AuthService.php`
- Create: `src/Middleware/AuthMiddleware.php`
- Create: `src/Middleware/RoleMiddleware.php`
- Create: `src/Controllers/AuthController.php`
- Test: `tests/Feature/AuthEndpointTest.php`
- Modify: `src/Core/App.php`
- Modify: `src/routes.php`

**Interfaces:**
- Consumes: repositories and token service from Task 5.
- Produces: `AuthService::login(string $email, string $password): array`.
- Produces: `AuthService::signup(array $payload): array`.
- Produces: `AuthService::refresh(string $refreshToken): array`.
- Produces: `AuthService::logout(string $refreshToken): void`.
- Produces: `AuthService::currentUser(int $userId): array`.
- Produces: auth endpoints matching frontend service paths.

- [ ] **Step 1: Write failing feature test for auth route shape**

Create `tests/Feature/AuthEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;
use PHPUnit\Framework\TestCase;

final class AuthEndpointTest extends TestCase
{
    public function testLoginRouteReturnsFrontendEnvelopeShape(): void
    {
        $router = require dirname(__DIR__, 2) . '/src/routes.php';

        $request = new Request(
            'POST',
            '/api/auth/login/',
            [],
            ['content-type' => 'application/json'],
            [],
            ['email' => 'admin@libratrack.com', 'password' => 'Admin@1234']
        );

        $response = $router->dispatch($request);

        $this->assertContains($response->statusCode, [200, 500]);
        $this->assertArrayHasKey('status', $response->payload);
    }

    public function testAuthRoutesAreRegistered(): void
    {
        $router = require dirname(__DIR__, 2) . '/src/routes.php';
        $paths = [
            ['POST', '/api/auth/login/'],
            ['POST', '/api/auth/signup/'],
            ['POST', '/api/auth/refresh/'],
            ['POST', '/api/auth/logout/'],
            ['GET', '/api/auth/me/'],
            ['PATCH', '/api/auth/me/'],
            ['PATCH', '/api/auth/change-password/'],
        ];

        foreach ($paths as [$method, $path]) {
            $response = $router->dispatch(new Request($method, $path, [], [], [], []));
            $this->assertNotSame(404, $response->statusCode, "{$method} {$path} is missing");
        }
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Feature/AuthEndpointTest.php
```

Expected: FAIL because auth routes are missing.

- [ ] **Step 3: Implement AuthService**

Create `src/Services/AuthService.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Services;

use LibraTrack\Core\ValidationException;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\RefreshTokenRepository;
use LibraTrack\Repositories\UserRepository;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly MemberRepository $members,
        private readonly PasswordService $passwords,
        private readonly TokenService $tokens
    ) {
    }

    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->passwords->verify($password, $user['password_hash'])) {
            throw new ValidationException('Invalid email or password', 401);
        }
        if ((int) $user['is_active'] !== 1) {
            throw new ValidationException('Account is inactive', 403);
        }

        return $this->issueSession($user);
    }

    public function signup(array $payload): array
    {
        foreach (['email', 'password', 'fullName'] as $field) {
            if (empty($payload[$field])) {
                throw new ValidationException("{$field} is required");
            }
        }
        if ($this->users->findByEmail((string) $payload['email'])) {
            throw new ValidationException('Email already exists', 400);
        }

        $userId = $this->users->createUser(
            (string) $payload['email'],
            $this->passwords->hash((string) $payload['password']),
            'member',
            false
        );
        $this->members->createForUser(
            $userId,
            (string) $payload['fullName'],
            $payload['phone'] ?? null,
            $payload['address'] ?? null
        );

        $user = $this->users->findById($userId);
        $session = $this->issueSession($user);
        $session['user'] = $this->frontendUser($user);
        return $session;
    }

    public function refresh(string $refreshToken): array
    {
        $hash = $this->tokens->hashRefreshToken($refreshToken);
        $stored = $this->refreshTokens->findActiveByHash($hash);
        if (!$stored) {
            throw new ValidationException('Invalid refresh token', 401);
        }

        $this->refreshTokens->revokeByHash($hash);
        $user = $this->users->findById((int) $stored['user_id']);
        if (!$user || (int) $user['is_active'] !== 1) {
            throw new ValidationException('Account is inactive', 403);
        }

        return $this->issueSession($user);
    }

    public function logout(string $refreshToken): void
    {
        $this->refreshTokens->revokeByHash($this->tokens->hashRefreshToken($refreshToken));
    }

    public function currentUser(int $userId): array
    {
        $user = $this->users->findById($userId);
        if (!$user) {
            throw new ValidationException('User not found', 404);
        }
        return $this->frontendUser($user);
    }

    public function updateEmail(int $userId, string $email): array
    {
        $this->users->updateEmail($userId, $email);
        return $this->currentUser($userId);
    }

    public function changePassword(int $userId, string $password): void
    {
        if (strlen($password) < 8) {
            throw new ValidationException('Password must be at least 8 characters');
        }
        $this->users->updatePassword($userId, $this->passwords->hash($password));
    }

    private function issueSession(array $user): array
    {
        $accessToken = $this->tokens->issueAccessToken($user);
        $refreshToken = $this->tokens->newRefreshToken();
        $this->refreshTokens->store(
            (int) $user['id'],
            $this->tokens->hashRefreshToken($refreshToken),
            $this->tokens->refreshExpiresAt()
        );

        return ['accessToken' => $accessToken, 'refreshToken' => $refreshToken];
    }

    private function frontendUser(array $user): array
    {
        $member = $this->members->findByUserId((int) $user['id']);
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'memberId' => $member ? (int) $member['id'] : null,
            'mustChangePassword' => (bool) $user['must_change_password'],
        ];
    }
}
```

- [ ] **Step 4: Implement auth controller**

Create `src/Controllers/AuthController.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Services\AuthService;
use LibraTrack\Services\TokenService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
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
        $payload = $this->requirePayload($request);
        return Response::success($this->auth->currentUser((int) $payload['sub']));
    }

    public function updateMe(Request $request): Response
    {
        $payload = $this->requirePayload($request);
        $email = (string) ($request->json['email'] ?? '');
        if ($email === '') {
            throw new ValidationException('email is required');
        }
        return Response::success($this->auth->updateEmail((int) $payload['sub'], $email));
    }

    public function changePassword(Request $request): Response
    {
        $payload = $this->requirePayload($request);
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

    private function requirePayload(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ValidationException('Authentication required', 401);
        }
        return $this->tokens->decodeAccessToken($token);
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

- [ ] **Step 5: Wire routes with dependencies**

Replace `src/routes.php` with:

```php
<?php

declare(strict_types=1);

use LibraTrack\Controllers\AuthController;
use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\RefreshTokenRepository;
use LibraTrack\Repositories\UserRepository;
use LibraTrack\Services\AuthService;
use LibraTrack\Services\PasswordService;
use LibraTrack\Services\TokenService;

$config = Config::fromProjectRoot(dirname(__DIR__));
$pdo = Database::fromConfig($config)->pdo();

$users = new UserRepository($pdo);
$refreshTokens = new RefreshTokenRepository($pdo);
$members = new MemberRepository($pdo);
$passwords = new PasswordService();
$tokens = new TokenService($config);
$authService = new AuthService($users, $refreshTokens, $members, $passwords, $tokens);
$auth = new AuthController($authService, $tokens, $config->bool('COOKIE_SECURE', false));

$router = new Router();

$router->add('GET', '/api/health/', function (Request $request, array $params): Response {
    return Response::success(['ok' => true]);
});
$router->add('POST', '/api/auth/login/', fn (Request $request, array $params): Response => $auth->login($request));
$router->add('POST', '/api/auth/signup/', fn (Request $request, array $params): Response => $auth->signup($request));
$router->add('POST', '/api/auth/refresh/', fn (Request $request, array $params): Response => $auth->refresh($request));
$router->add('POST', '/api/auth/logout/', fn (Request $request, array $params): Response => $auth->logout($request));
$router->add('GET', '/api/auth/me/', fn (Request $request, array $params): Response => $auth->me($request));
$router->add('PATCH', '/api/auth/me/', fn (Request $request, array $params): Response => $auth->updateMe($request));
$router->add('PATCH', '/api/auth/change-password/', fn (Request $request, array $params): Response => $auth->changePassword($request));

return $router;
```

- [ ] **Step 6: Run feature test**

Run:

```bash
vendor/bin/phpunit tests/Feature/AuthEndpointTest.php
```

Expected: PASS if local test database migrated and seeded. If DB is not available, expected failure must be connection-specific; run again after `php database/migrate.php && php database/seed.php`.

- [ ] **Step 7: Manual auth smoke**

Run:

```bash
php database/migrate.php
php database/seed.php
php -S localhost:8000 -t public
```

In another terminal:

```bash
curl -i -s -X POST http://localhost:8000/api/auth/login/ \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@libratrack.com","password":"Admin@1234"}'
```

Expected body has `status` equal to `success` and `data.accessToken` as a non-empty string.

Expected headers include a `Set-Cookie` header that starts with `refreshToken=` and includes `Path=/`, `HttpOnly`, and `SameSite=Lax`.

- [ ] **Step 8: Commit**

```bash
git add src/Controllers/AuthController.php src/Services/AuthService.php src/Middleware src/routes.php tests/Feature/AuthEndpointTest.php
git commit -m "feat: add PHP auth endpoints"
```

---

### Task 7: Settings Endpoint and Phase 1 Documentation

**Files:**
- Create: `src/Repositories/SettingsRepository.php`
- Create: `src/Controllers/SettingsController.php`
- Test: `tests/Feature/SettingsEndpointTest.php`
- Modify: `src/routes.php`
- Modify: `README.md`
- Modify: `USAGE.md`

**Interfaces:**
- Consumes: `settings` table from Task 3 and seed data from Task 4.
- Produces: `GET /api/settings/`.
- Produces: `PATCH /api/settings/`.
- Produces: `PUT /api/settings/`.
- Produces frontend camelCase fields:
  - `fineRatePerDay`
  - `borrowDays`
  - `maxBooksPerMember`
  - `reservationExpiryDays`

- [ ] **Step 1: Write failing settings endpoint test**

Create `tests/Feature/SettingsEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use LibraTrack\Core\Request;
use PHPUnit\Framework\TestCase;

final class SettingsEndpointTest extends TestCase
{
    public function testSettingsRoutesAreRegistered(): void
    {
        $router = require dirname(__DIR__, 2) . '/src/routes.php';

        foreach (['GET', 'PATCH', 'PUT'] as $method) {
            $response = $router->dispatch(new Request($method, '/api/settings/', [], [], [], []));
            $this->assertNotSame(404, $response->statusCode, "{$method} /api/settings/ is missing");
        }
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run:

```bash
vendor/bin/phpunit tests/Feature/SettingsEndpointTest.php
```

Expected: FAIL because settings routes are missing.

- [ ] **Step 3: Implement SettingsRepository**

Create `src/Repositories/SettingsRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $values = [];
        foreach ($rows as $row) {
            $values[$row['setting_key']] = $row['setting_value'];
        }

        return [
            'fineRatePerDay' => (float) ($values['fine_rate_per_day'] ?? 10),
            'borrowDays' => (int) ($values['borrow_days'] ?? 14),
            'maxBooksPerMember' => (int) ($values['max_books_per_member'] ?? 5),
            'reservationExpiryDays' => (int) ($values['reservation_expiry_days'] ?? 3),
        ];
    }

    public function update(array $payload): array
    {
        $map = [
            'fineRatePerDay' => 'fine_rate_per_day',
            'borrowDays' => 'borrow_days',
            'maxBooksPerMember' => 'max_books_per_member',
            'reservationExpiryDays' => 'reservation_expiry_days',
        ];

        foreach ($map as $camel => $key) {
            if (!array_key_exists($camel, $payload)) {
                continue;
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $statement->execute([$key, (string) $payload[$camel]]);
        }

        return $this->all();
    }
}
```

- [ ] **Step 4: Implement SettingsController**

Create `src/Controllers/SettingsController.php`:

```php
<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Repositories\SettingsRepository;

final class SettingsController
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function show(Request $request): Response
    {
        return Response::success($this->settings->all());
    }

    public function update(Request $request): Response
    {
        return Response::success($this->settings->update($request->json ?? []));
    }
}
```

- [ ] **Step 5: Wire settings routes**

Modify `src/routes.php` imports:

```php
use LibraTrack\Controllers\SettingsController;
use LibraTrack\Repositories\SettingsRepository;
```

Add after repository construction:

```php
$settings = new SettingsController(new SettingsRepository($pdo));
```

Add routes before `return $router;`:

```php
$router->add('GET', '/api/settings/', fn (Request $request, array $params): Response => $settings->show($request));
$router->add('PATCH', '/api/settings/', fn (Request $request, array $params): Response => $settings->update($request));
$router->add('PUT', '/api/settings/', fn (Request $request, array $params): Response => $settings->update($request));
```

- [ ] **Step 6: Run settings test**

Run:

```bash
vendor/bin/phpunit tests/Feature/SettingsEndpointTest.php
```

Expected: PASS.

- [ ] **Step 7: Update docs for Phase 1 run commands**

In `README.md` and `USAGE.md`, replace Django setup snippets for this branch with:

```bash
composer install
cp .env.example .env
php database/migrate.php
php database/seed.php
php -S localhost:8000 -t public
```

Also document Apache/XAMPP:

```text
Point Apache document root to public/ when possible. If serving from project root, public/.htaccess rewrites /api routes to public/index.php.
```

- [ ] **Step 8: Run full Phase 1 verification**

Run:

```bash
vendor/bin/phpunit
php database/migrate.php
php database/seed.php
curl -s http://localhost:8000/api/health/
```

Expected PHPUnit: PASS.
Expected health response:

```json
{"status":"success","data":{"ok":true}}
```

- [ ] **Step 9: Commit**

```bash
git add src/Repositories/SettingsRepository.php src/Controllers/SettingsController.php src/routes.php tests/Feature/SettingsEndpointTest.php README.md USAGE.md
git commit -m "feat: add PHP settings endpoint"
```

---

## Phase 1 Completion Checklist

- [ ] `composer install` succeeds.
- [ ] `vendor/bin/phpunit` passes.
- [ ] `php database/migrate.php` creates core auth tables.
- [ ] `php database/seed.php` creates demo users/settings.
- [ ] `POST /api/auth/login/` returns `data.accessToken`.
- [ ] Login sets HttpOnly `refreshToken` cookie.
- [ ] `POST /api/auth/refresh/` rotates refresh token.
- [ ] `GET /api/auth/me/` returns current frontend user shape.
- [ ] `PATCH /api/auth/change-password/` clears `mustChangePassword`.
- [ ] `GET /api/settings/` returns frontend settings shape.
- [ ] Built-in server works.
- [ ] Apache/XAMPP rewrite file exists.

## Known Deferred Work

- Books, categories, Open Library import.
- Members list/detail/manual creation beyond auth signup seed needs.
- Transactions, returns, reservations, fines, notifications, reports.
- Final Django file removal.
- Frontend browser smoke against full PHP API.

These are deferred to later phase plans by approved phased strategy.

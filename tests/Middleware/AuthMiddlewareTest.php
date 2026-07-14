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

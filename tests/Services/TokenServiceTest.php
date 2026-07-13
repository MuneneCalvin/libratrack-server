<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use LibraTrack\Core\Config;
use LibraTrack\Core\ValidationException;
use LibraTrack\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    public function testIssuesAndDecodesAccessToken(): void
    {
        $service = new TokenService(new Config([
            'JWT_SECRET' => 'unit-test-secret-that-is-long-enough-for-jwt',
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
            'JWT_SECRET' => 'unit-test-secret-that-is-long-enough-for-jwt',
            'JWT_ACCESS_TTL_MINUTES' => '15',
            'JWT_REFRESH_TTL_DAYS' => '7',
        ]));

        $hash = $service->hashRefreshToken('abc');

        $this->assertSame(hash('sha256', 'abc'), $hash);
    }

    public function testDecodingExpiredTokenThrowsValidationExceptionWith401(): void
    {
        $secret = 'unit-test-secret-that-is-long-enough-for-jwt';
        $service = new TokenService(new Config([
            'JWT_SECRET' => $secret,
            'JWT_ACCESS_TTL_MINUTES' => '15',
            'JWT_REFRESH_TTL_DAYS' => '7',
        ]));

        $expiredToken = JWT::encode([
            'sub' => '7',
            'email' => 'admin@libratrack.com',
            'role' => 'admin',
            'iat' => time() - 3600,
            'exp' => time() - 1800,
        ], $secret, 'HS256');

        try {
            $service->decodeAccessToken($expiredToken);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(401, $exception->statusCode);
        }
    }
}

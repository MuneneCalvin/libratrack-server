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
}

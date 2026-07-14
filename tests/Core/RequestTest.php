<?php

declare(strict_types=1);

use LibraTrack\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private array $serverBackup;
    private array $getBackup;
    private array $cookieBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->cookieBackup = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_COOKIE = $this->cookieBackup;
    }

    public function testBearerTokenFromStandardAuthorizationHeader(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/auth/me/',
            'HTTP_AUTHORIZATION' => 'Bearer normal-token',
        ];
        $_GET = [];
        $_COOKIE = [];

        $request = Request::fromGlobals();

        $this->assertSame('normal-token', $request->bearerToken());
    }

    public function testBearerTokenFromApacheRedirectAuthorizationHeader(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/auth/me/',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirect-token',
        ];
        $_GET = [];
        $_COOKIE = [];

        $request = Request::fromGlobals();

        $this->assertSame('redirect-token', $request->bearerToken());
    }

    public function testBearerTokenFromDirectAuthorizationServerValue(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/auth/me/',
            'Authorization' => 'Bearer direct-token',
        ];
        $_GET = [];
        $_COOKIE = [];

        $request = Request::fromGlobals();

        $this->assertSame('direct-token', $request->bearerToken());
    }
}

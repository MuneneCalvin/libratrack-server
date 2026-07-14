<?php

declare(strict_types=1);

use LibraTrack\Core\App;
use LibraTrack\Core\Config;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\Router;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    public function testCorsPreflightReturnsNoContentForAllowedOrigin(): void
    {
        $app = new App(new Router(), new Config([
            'CORS_ALLOWED_ORIGINS' => 'http://localhost:5173',
        ]));

        $response = $app->handle(new Request(
            'OPTIONS',
            '/api/auth/login/',
            [],
            ['origin' => 'http://localhost:5173'],
            [],
            null
        ));

        $this->assertSame(204, $response->statusCode);
        $this->assertSame('', $response->rawBody);
        $this->assertSame('http://localhost:5173', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
        $this->assertStringContainsString('Authorization', $response->headers['Access-Control-Allow-Headers']);
        $this->assertStringContainsString('POST', $response->headers['Access-Control-Allow-Methods']);
    }

    public function testCorsHeadersAreAddedToNormalAllowedOriginResponse(): void
    {
        $router = new Router();
        $router->add('GET', '/api/health/', fn (): Response => Response::success(['ok' => true]));
        $app = new App($router, new Config([
            'CORS_ALLOWED_ORIGINS' => 'http://localhost:5173',
        ]));

        $response = $app->handle(new Request(
            'GET',
            '/api/health/',
            [],
            ['origin' => 'http://localhost:5173'],
            [],
            null
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('http://localhost:5173', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
    }
}

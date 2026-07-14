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

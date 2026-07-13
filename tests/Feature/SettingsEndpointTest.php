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

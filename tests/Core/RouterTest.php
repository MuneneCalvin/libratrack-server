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

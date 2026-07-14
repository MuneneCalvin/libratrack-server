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

    public function testRouteRegexEscapesLiteralCharacters(): void
    {
        $router = new Router();
        $router->add('GET', '/api/files/{name}.json/', function (Request $request, array $params): Response {
            return Response::success(['name' => $params['name']]);
        });

        $matching = $router->dispatch(new Request('GET', '/api/files/catalog.json/', [], [], [], null));
        $nonMatching = $router->dispatch(new Request('GET', '/api/files/catalogxjson/', [], [], [], null));

        $this->assertSame(200, $matching->statusCode);
        $this->assertSame(['status' => 'success', 'data' => ['name' => 'catalog']], $matching->payload);
        $this->assertSame(404, $nonMatching->statusCode);
    }

    public function testUnexpectedErrorsAreHiddenByDefault(): void
    {
        $router = new Router();
        $router->add('GET', '/api/failing/', function (): Response {
            throw new RuntimeException('database exploded');
        });

        $response = $router->dispatch(new Request('GET', '/api/failing/', [], [], [], null));

        $this->assertSame(500, $response->statusCode);
        $this->assertSame('Internal server error', $response->payload['message']);
        $this->assertArrayNotHasKey('detail', $response->payload);
    }

    public function testUnexpectedErrorsIncludeDetailInDebugMode(): void
    {
        $router = new Router(true);
        $router->add('GET', '/api/failing/', function (): Response {
            throw new RuntimeException('database exploded');
        });

        $response = $router->dispatch(new Request('GET', '/api/failing/', [], [], [], null));

        $this->assertSame(500, $response->statusCode);
        $this->assertSame('Internal server error', $response->payload['message']);
        $this->assertSame('database exploded', $response->payload['detail']);
        $this->assertSame(RuntimeException::class, $response->payload['exception']);
    }
}

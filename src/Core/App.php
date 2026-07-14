<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class App
{
    public function __construct(
        private readonly Router $router,
        private readonly ?Config $config = null
    )
    {
    }

    public static function fromConfig(Config $config): self
    {
        $router = require dirname(__DIR__) . '/routes.php';
        return new self($router, $config);
    }

    public function handle(Request $request): Response
    {
        $response = $request->method === 'OPTIONS'
            ? new Response([], 204, [], '')
            : $this->router->dispatch($request);

        return $this->withCors($request, $response);
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }

    private function withCors(Request $request, Response $response): Response
    {
        $origin = $request->headers['origin'] ?? '';
        if ($origin === '' || !$this->isAllowedOrigin($origin)) {
            return $response;
        }

        return new Response($response->payload, $response->statusCode, array_merge($response->headers, [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Vary' => 'Origin',
        ]), $response->rawBody);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $allowed = $this->config?->get('CORS_ALLOWED_ORIGINS', '') ?? '';
        $origins = array_filter(array_map('trim', explode(',', $allowed)));

        return in_array($origin, $origins, true);
    }
}

<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class App
{
    public function __construct(private readonly Router $router)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $router = require dirname(__DIR__) . '/routes.php';
        return new self($router);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            return Response::error($exception->getMessage(), $exception->statusCode);
        } catch (\Throwable) {
            return Response::error('Internal server error', 500);
        }
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}

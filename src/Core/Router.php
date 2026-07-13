<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_quote($pattern, '#');
        $regex = preg_replace('/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/', '(?P<$1>[^/]+)', $regex);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            $params = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
            return ($route['handler'])($request, $params);
        }

        return Response::error('Route not found', 404);
    }
}

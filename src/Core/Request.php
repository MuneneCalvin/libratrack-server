<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly ?array $json
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $body = file_get_contents('php://input') ?: '';
        $json = $body === '' ? null : json_decode($body, true);

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::normalizePath($path),
            $_GET,
            self::headersFromServer($_SERVER),
            $_COOKIE,
            is_array($json) ? $json : null
        );
    }

    public static function normalizePath(string $path): string
    {
        if ($path !== '/' && str_ends_with($path, '/') === false) {
            return $path . '/';
        }
        return $path;
    }

    public function bearerToken(): ?string
    {
        $header = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                $headers['authorization'] = (string) $server[$key];
                break;
            }
        }
        return $headers;
    }
}

<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Response
{
    public function __construct(
        public readonly array $payload,
        public readonly int $statusCode = 200,
        public readonly array $headers = []
    ) {
    }

    public static function success(mixed $data = null, int $status = 200): self
    {
        return new self(['status' => 'success', 'data' => $data], $status);
    }

    public static function paginated(array $data, array $meta): self
    {
        return new self(['status' => 'success', 'data' => $data, 'meta' => $meta]);
    }

    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return new self(array_merge(['status' => 'error', 'message' => $message], $extra), $status);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($this->payload, JSON_UNESCAPED_SLASHES);
    }
}

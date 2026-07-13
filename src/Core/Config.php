<?php

declare(strict_types=1);

namespace LibraTrack\Core;

use Dotenv\Dotenv;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public static function fromProjectRoot(string $root): self
    {
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        return new self($_ENV + getenv());
    }

    public function get(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;
        if ($value === null) {
            throw new \RuntimeException("Missing config value: {$key}");
        }
        return (string) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->values[$key] ?? $default, FILTER_VALIDATE_BOOL);
    }
}

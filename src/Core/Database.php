<?php

declare(strict_types=1);

namespace LibraTrack\Core;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->get('DB_PORT', '3306'),
            $config->get('DB_NAME'),
        );

        return new self($dsn, $config->get('DB_USER'), $config->get('DB_PASSWORD', ''));
    }

    public function dsn(): string
    {
        return $this->dsn;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }
}

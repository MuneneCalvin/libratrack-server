<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use DateTimeImmutable;
use PDO;

final class RefreshTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(int $userId, string $hash, DateTimeImmutable $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $hash, $expiresAt->format('Y-m-d H:i:s')]);
    }

    public function findActiveByHash(string $hash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM refresh_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'
        );
        $statement->execute([$hash]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function revokeByHash(string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL'
        );
        $statement->execute([$hash]);
    }
}

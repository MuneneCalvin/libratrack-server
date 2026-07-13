<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class MemberRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createForUser(int $userId, string $fullName, ?string $phone, ?string $address): int
    {
        $membershipNumber = 'MEM-' . strtoupper(bin2hex(random_bytes(3)));
        $statement = $this->pdo->prepare(
            'INSERT INTO members (user_id, full_name, phone, address, membership_number, joined_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $statement->execute([$userId, $fullName, $phone, $address, $membershipNumber]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findByUserId(int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM members WHERE user_id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch();
        return $row ?: null;
    }
}

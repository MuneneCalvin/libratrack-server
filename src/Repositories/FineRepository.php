<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class FineRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByTransaction(int $transactionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM fines WHERE transaction_id = ?');
        $statement->execute([$transactionId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function createForOverdueReturn(int $memberId, int $transactionId, float $amount, string $reason): void
    {
        if ($this->findByTransaction($transactionId) !== null) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO fines (member_id, transaction_id, amount, reason, status)
             VALUES (?, ?, ?, ?, \'unpaid\')'
        );
        $statement->execute([$memberId, $transactionId, $amount, $reason]);
    }
}

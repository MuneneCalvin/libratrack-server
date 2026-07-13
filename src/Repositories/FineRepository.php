<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
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

    public function search(array $filters, Pagination $pagination): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $countStatement = $this->pdo->prepare("SELECT COUNT(*) FROM fines JOIN members ON members.id = fines.member_id {$where}");
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = "SELECT fines.*, members.full_name AS member_full_name
                FROM fines
                JOIN members ON members.id = fines.member_id
                {$where}
                ORDER BY fines.created_at DESC
                LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT fines.*, members.full_name AS member_full_name
             FROM fines
             JOIN members ON members.id = fines.member_id
             WHERE fines.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findByMember(int $memberId, ?bool $isPaid): array
    {
        $where = 'WHERE fines.member_id = ?';
        $params = [$memberId];
        if ($isPaid !== null) {
            $where .= ' AND fines.status = ?';
            $params[] = $isPaid ? 'paid' : 'unpaid';
        }

        $statement = $this->pdo->prepare(
            "SELECT fines.*, members.full_name AS member_full_name
             FROM fines
             JOIN members ON members.id = fines.member_id
             {$where}
             ORDER BY fines.created_at DESC"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function markPaid(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE fines SET status = 'paid', paid_at = NOW() WHERE id = ?");
        $statement->execute([$id]);
    }

    public function markWaived(int $id, ?string $note): void
    {
        $statement = $this->pdo->prepare("UPDATE fines SET status = 'waived', waived_at = NOW(), waived_note = ? WHERE id = ?");
        $statement->execute([$note, $id]);
    }

    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['memberId'])) {
            $clauses[] = 'fines.member_id = :member_id';
            $params[':member_id'] = $filters['memberId'];
        }
        if (!empty($filters['q'])) {
            $clauses[] = '(members.full_name LIKE :q_name OR fines.reason LIKE :q_reason)';
            $params[':q_name'] = '%' . $filters['q'] . '%';
            $params[':q_reason'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['status'])) {
            $statusFilter = strtoupper((string) $filters['status']);
            if ($statusFilter === 'PAID') {
                $clauses[] = "fines.status = 'paid'";
            } elseif ($statusFilter === 'WAIVED') {
                $clauses[] = "fines.status = 'waived'";
            } elseif ($statusFilter === 'UNPAID') {
                $clauses[] = "fines.status = 'unpaid'";
            }
        } elseif (array_key_exists('isPaid', $filters) && $filters['isPaid'] !== null) {
            $clauses[] = 'fines.status = :legacy_status';
            $params[':legacy_status'] = $filters['isPaid'] ? 'paid' : 'unpaid';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}

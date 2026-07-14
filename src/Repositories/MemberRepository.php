<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
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

    public function search(?string $q, Pagination $pagination): array
    {
        $where = '';
        $params = [];
        if ($q !== null && $q !== '') {
            $where = 'WHERE members.full_name LIKE ? OR members.membership_number LIKE ?';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }

        $countStatement = $this->pdo->prepare("SELECT COUNT(*) FROM members {$where}");
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = "SELECT members.*, users.email AS email, users.is_active AS is_active
                FROM members
                JOIN users ON users.id = members.user_id
                {$where}
                ORDER BY members.joined_at DESC
                LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value);
        }
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT members.*, users.email AS email, users.is_active AS is_active
             FROM members
             JOIN users ON users.id = members.user_id
             WHERE members.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findByMembershipNumber(string $number): ?array
    {
        $statement = $this->pdo->prepare('SELECT id FROM members WHERE membership_number = ?');
        $statement->execute([$number]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function updateProfile(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($fields)));
        $fields['id'] = $id;

        $statement = $this->pdo->prepare("UPDATE members SET {$assignments} WHERE id = :id");
        $statement->execute($fields);
    }

    public function countActiveBorrows(int $memberId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM transaction_items
             JOIN transactions ON transactions.id = transaction_items.transaction_id
             WHERE transactions.member_id = ?
               AND transactions.status IN ('ACTIVE', 'OVERDUE')
               AND transaction_items.returned_at IS NULL"
        );
        $statement->execute([$memberId]);
        return (int) $statement->fetchColumn();
    }

    public function deleteCascade(int $id): void
    {
        $member = $this->find($id);
        if ($member === null) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM reservations WHERE member_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM fines WHERE member_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM transactions WHERE member_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $member['user_id']]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}

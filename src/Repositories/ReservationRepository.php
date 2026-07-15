<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use DateTimeImmutable;
use LibraTrack\Core\Pagination;
use PDO;

final class ReservationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(Pagination $pagination): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT reservations.*, members.full_name AS member_full_name,
                    books.title AS book_title, books.author AS book_author, books.cover_url AS book_cover_url
             FROM reservations
             JOIN members ON members.id = reservations.member_id
             JOIN books ON books.id = reservations.book_id
             ORDER BY reservations.reserved_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT reservations.*, members.full_name AS member_full_name,
                    books.title AS book_title, books.author AS book_author, books.cover_url AS book_cover_url
             FROM reservations
             JOIN members ON members.id = reservations.member_id
             JOIN books ON books.id = reservations.book_id
             WHERE reservations.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findByMember(int $memberId, ?string $status): array
    {
        $where = 'WHERE reservations.member_id = ?';
        $params = [$memberId];
        if ($status !== null && $status !== '') {
            $where .= ' AND reservations.status = ?';
            $params[] = $status;
        }

        $statement = $this->pdo->prepare(
            "SELECT reservations.*, members.full_name AS member_full_name,
                    books.title AS book_title, books.author AS book_author, books.cover_url AS book_cover_url
             FROM reservations
             JOIN members ON members.id = reservations.member_id
             JOIN books ON books.id = reservations.book_id
             {$where}
             ORDER BY reservations.reserved_at DESC"
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function create(int $memberId, int $bookId, DateTimeImmutable $expiresAt): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO reservations (member_id, book_id, expires_at, status) VALUES (?, ?, ?, 'PENDING')"
        );
        $statement->execute([$memberId, $bookId, $expiresAt->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $id, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?');
        $statement->execute([$status, $id]);
    }

    public function approveHold(int $id, DateTimeImmutable $expiresAt): bool
    {
        $reservation = $this->find($id);
        if ($reservation === null || $reservation['status'] !== 'PENDING') {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $decrement = $this->pdo->prepare(
                'UPDATE books SET available_copies = available_copies - 1 WHERE id = ? AND available_copies > 0'
            );
            $decrement->execute([(int) $reservation['book_id']]);
            if ($decrement->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            $update = $this->pdo->prepare(
                "UPDATE reservations SET status = 'READY_FOR_PICKUP', expires_at = ? WHERE id = ? AND status = 'PENDING'"
            );
            $update->execute([$expiresAt->format('Y-m-d H:i:s'), $id]);
            if ($update->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markBorrowed(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE reservations SET status = 'BORROWED' WHERE id = ?");
        $statement->execute([$id]);
    }

    public function cancelWithRelease(int $id): void
    {
        $reservation = $this->find($id);
        if ($reservation === null) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            if ($reservation['status'] === 'READY_FOR_PICKUP') {
                $increment = $this->pdo->prepare(
                    'UPDATE books SET available_copies = LEAST(total_copies, available_copies + 1) WHERE id = ?'
                );
                $increment->execute([(int) $reservation['book_id']]);
            }
            $statement = $this->pdo->prepare("UPDATE reservations SET status = 'CANCELLED' WHERE id = ?");
            $statement->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function expireReadyForPickup(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            "SELECT id, book_id FROM reservations WHERE status = 'READY_FOR_PICKUP' AND expires_at < ?"
        );
        $statement->execute([$now->format('Y-m-d H:i:s')]);
        $rows = $statement->fetchAll();
        if ($rows === []) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $expire = $this->pdo->prepare("UPDATE reservations SET status = 'EXPIRED' WHERE id = ? AND status = 'READY_FOR_PICKUP'");
            $release = $this->pdo->prepare('UPDATE books SET available_copies = LEAST(total_copies, available_copies + 1) WHERE id = ?');
            $count = 0;
            foreach ($rows as $row) {
                $expire->execute([(int) $row['id']]);
                if ($expire->rowCount() > 0) {
                    $release->execute([(int) $row['book_id']]);
                    $count++;
                }
            }
            $this->pdo->commit();
            return $count;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function deleteForMember(int $memberId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM reservations WHERE member_id = ?');
        $statement->execute([$memberId]);
    }
}

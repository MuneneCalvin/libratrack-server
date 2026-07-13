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

    public function deleteForMember(int $memberId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM reservations WHERE member_id = ?');
        $statement->execute([$memberId]);
    }
}

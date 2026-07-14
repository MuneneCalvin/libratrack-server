<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function searchForUser(int $userId, Pagination $pagination): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $count->execute([$userId]);
        $total = (int) $count->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT * FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
        $statement->execute([$id, $userId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function markRead(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
        $statement->execute([$id]);
    }

    public function markAllReadForUser(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $statement->execute([$userId]);
    }

    public function createIfMissing(int $userId, string $title, string $message, string $type): bool
    {
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = ? AND message = ? AND type = ?'
        );
        $exists->execute([$userId, $title, $message, $type]);
        if ((int) $exists->fetchColumn() > 0) {
            return false;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$userId, $title, $message, $type]);
        return true;
    }

    public function sendOverdueReminders(): int
    {
        $transactions = $this->pdo->query(
            "SELECT transactions.id, transactions.due_date, members.user_id
             FROM transactions
             JOIN members ON members.id = transactions.member_id
             WHERE transactions.status = 'OVERDUE'
             ORDER BY transactions.due_date ASC"
        )->fetchAll();

        $bookStatement = $this->pdo->prepare(
            'SELECT books.title
             FROM transaction_items
             JOIN books ON books.id = transaction_items.book_id
             WHERE transaction_items.transaction_id = ?
             ORDER BY books.title ASC'
        );

        $sent = 0;
        foreach ($transactions as $transaction) {
            $bookStatement->execute([(int) $transaction['id']]);
            $titles = array_column($bookStatement->fetchAll(), 'title');
            $titleText = $titles === [] ? 'your borrowed book' : implode(', ', $titles);
            $dueDate = (new \DateTimeImmutable($transaction['due_date']))->format('Y-m-d');
            $message = "Please return {$titleText}. The due date was {$dueDate}.";

            if ($this->createIfMissing((int) $transaction['user_id'], 'Overdue Book Reminder', $message, 'OVERDUE')) {
                $sent++;
            }
        }

        return $sent;
    }
}

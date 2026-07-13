<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use DateTimeImmutable;
use LibraTrack\Core\Pagination;
use PDO;

final class TransactionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(array $filters, Pagination $pagination): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $joins = !empty($filters['q']) || !empty($filters['bookId'])
            ? 'JOIN members ON members.id = transactions.member_id
               JOIN transaction_items ON transaction_items.transaction_id = transactions.id
               JOIN books ON books.id = transaction_items.book_id'
            : '';

        $countSql = "SELECT COUNT(DISTINCT transactions.id) FROM transactions {$joins} {$where}";
        $countStatement = $this->pdo->prepare($countSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = "SELECT DISTINCT transactions.*
                FROM transactions
                {$joins}
                {$where}
                ORDER BY transactions.borrowed_at DESC
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
        $statement = $this->pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findWithItems(int $id): ?array
    {
        $transaction = $this->find($id);
        if ($transaction === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT transaction_items.id AS item_id, transaction_items.returned_at AS item_returned_at, books.*
             FROM transaction_items
             JOIN books ON books.id = transaction_items.book_id
             WHERE transaction_items.transaction_id = ?
             ORDER BY transaction_items.id ASC'
        );
        $statement->execute([$id]);
        $transaction['items'] = $statement->fetchAll();

        return $transaction;
    }

    public function findByMember(int $memberId, ?string $status): array
    {
        $where = 'WHERE member_id = ?';
        $params = [$memberId];
        if ($status !== null && $status !== '') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $statement = $this->pdo->prepare("SELECT * FROM transactions {$where} ORDER BY borrowed_at DESC");
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function create(int $memberId, array $bookIds, DateTimeImmutable $dueDate): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transactions (member_id, due_date, status) VALUES (?, ?, \'ACTIVE\')'
        );
        $statement->execute([$memberId, $dueDate->format('Y-m-d H:i:s')]);
        $transactionId = (int) $this->pdo->lastInsertId();

        $insertItem = $this->pdo->prepare('INSERT INTO transaction_items (transaction_id, book_id) VALUES (?, ?)');
        $decrementBook = $this->pdo->prepare('UPDATE books SET available_copies = available_copies - 1 WHERE id = ?');
        foreach ($bookIds as $bookId) {
            $insertItem->execute([$transactionId, $bookId]);
            $decrementBook->execute([$bookId]);
        }

        return $transactionId;
    }

    public function unreturnedItems(int $transactionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT transaction_items.id, transaction_items.book_id
             FROM transaction_items
             WHERE transaction_items.transaction_id = ? AND transaction_items.returned_at IS NULL'
        );
        $statement->execute([$transactionId]);

        return $statement->fetchAll();
    }

    public function returnItems(int $transactionId, array $itemIds): void
    {
        $markReturned = $this->pdo->prepare('UPDATE transaction_items SET returned_at = NOW() WHERE id = ?');
        $incrementBook = $this->pdo->prepare('UPDATE books SET available_copies = available_copies + 1 WHERE id = ?');

        foreach ($itemIds as ['id' => $itemId, 'book_id' => $bookId]) {
            $markReturned->execute([$itemId]);
            $incrementBook->execute([$bookId]);
        }

        $remaining = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transaction_items WHERE transaction_id = ? AND returned_at IS NULL'
        );
        $remaining->execute([$transactionId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $this->pdo->prepare("UPDATE transactions SET status = 'RETURNED', returned_at = NOW() WHERE id = ?")
                ->execute([$transactionId]);
        }
    }

    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'transactions.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['memberId'])) {
            $clauses[] = 'transactions.member_id = :member_id';
            $params[':member_id'] = $filters['memberId'];
        }
        if (!empty($filters['bookId'])) {
            $clauses[] = 'books.id = :book_id';
            $params[':book_id'] = $filters['bookId'];
        }
        if (!empty($filters['q'])) {
            $clauses[] = '(members.full_name LIKE :q_name OR books.title LIKE :q_title OR books.author LIKE :q_author)';
            $params[':q_name'] = '%' . $filters['q'] . '%';
            $params[':q_title'] = '%' . $filters['q'] . '%';
            $params[':q_author'] = '%' . $filters['q'] . '%';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}

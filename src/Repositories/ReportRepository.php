<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class ReportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function summary(): array
    {
        $totalBooks = (int) $this->pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
        $copies = $this->pdo->query('SELECT COALESCE(SUM(total_copies), 0) AS total_copies, COALESCE(SUM(available_copies), 0) AS available_copies FROM books')->fetch();
        $totalMembers = (int) $this->pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
        $activeBorrows = (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'ACTIVE'")->fetchColumn();
        $borrowedBooks = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM transaction_items
             JOIN transactions ON transactions.id = transaction_items.transaction_id
             WHERE transactions.status = 'ACTIVE' AND transaction_items.returned_at IS NULL"
        )->fetchColumn();
        $overdueCount = (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'OVERDUE'")->fetchColumn();
        $pendingReservations = (int) $this->pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'PENDING'")->fetchColumn();
        $unpaidFinesTotal = (float) $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'unpaid'")->fetchColumn();

        return [
            'totalBooks' => $totalBooks,
            'totalCopies' => (int) $copies['total_copies'],
            'availableBooks' => (int) $copies['available_copies'],
            'availableCopies' => (int) $copies['available_copies'],
            'borrowedBooks' => $borrowedBooks,
            'reservedBooks' => $pendingReservations,
            'totalMembers' => $totalMembers,
            'activeBorrows' => $activeBorrows,
            'overdueCount' => $overdueCount,
            'pendingReservations' => $pendingReservations,
            'unpaidFinesTotal' => $unpaidFinesTotal,
        ];
    }

    public function borrowing(): array
    {
        return [
            'active' => $this->countTransactions('ACTIVE'),
            'overdue' => $this->countTransactions('OVERDUE'),
            'returned' => $this->countTransactions('RETURNED'),
        ];
    }

    public function inventory(): array
    {
        $rows = $this->pdo->query(
            'SELECT categories.name, COUNT(books.id) AS count
             FROM categories
             LEFT JOIN books ON books.category_id = categories.id
             GROUP BY categories.id, categories.name
             ORDER BY count DESC, categories.name ASC'
        )->fetchAll();

        return ['categories' => array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'count' => (int) $row['count'],
        ], $rows)];
    }

    public function fines(): array
    {
        return [
            'total' => $this->money('SELECT COALESCE(SUM(amount), 0) FROM fines'),
            'paid' => $this->money("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'paid'"),
            'unpaid' => $this->money("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE status = 'unpaid'"),
        ];
    }

    public function overdue(): array
    {
        $transactions = $this->pdo->query(
            "SELECT transactions.id, transactions.member_id, transactions.due_date, members.full_name AS member_name
             FROM transactions
             JOIN members ON members.id = transactions.member_id
             WHERE transactions.status = 'OVERDUE'
             ORDER BY transactions.due_date ASC"
        )->fetchAll();

        $books = $this->pdo->prepare(
            'SELECT books.id, books.title
             FROM transaction_items
             JOIN books ON books.id = transaction_items.book_id
             WHERE transaction_items.transaction_id = ?
             ORDER BY books.title ASC'
        );

        return array_map(function (array $row) use ($books): array {
            $books->execute([(int) $row['id']]);
            return [
                'id' => (int) $row['id'],
                'memberId' => (int) $row['member_id'],
                'memberName' => $row['member_name'],
                'dueDate' => (new \DateTimeImmutable($row['due_date']))->format(\DateTimeInterface::ATOM),
                'books' => array_map(static fn (array $book): array => [
                    'id' => (int) $book['id'],
                    'title' => $book['title'],
                ], $books->fetchAll()),
            ];
        }, $transactions);
    }

    public function popularBooks(): array
    {
        $rows = $this->pdo->query(
            'SELECT books.id, books.title, books.author, COUNT(transaction_items.id) AS borrow_count
             FROM books
             LEFT JOIN transaction_items ON transaction_items.book_id = books.id
             GROUP BY books.id, books.title, books.author
             ORDER BY borrow_count DESC, books.title ASC
             LIMIT 20'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'author' => $row['author'],
            'borrowCount' => (int) $row['borrow_count'],
        ], $rows);
    }

    public function members(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
        $active = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM members JOIN users ON users.id = members.user_id WHERE users.is_active = 1'
        )->fetchColumn();

        return [
            'totalMembers' => $total,
            'activeMembers' => $active,
            'inactiveMembers' => $total - $active,
        ];
    }

    public function csvRows(string $report): ?array
    {
        return match ($report) {
            'borrowing' => array_map(static fn (string $key, int $value): array => [$key, $value], array_keys($this->borrowing()), $this->borrowing()),
            'inventory' => $this->inventoryCsvRows(),
            'fines' => array_map(static fn (string $key, string $value): array => [$key, $value], array_keys($this->fines()), $this->fines()),
            'members' => array_map(static fn (string $key, int $value): array => [$key, $value], array_keys($this->members()), $this->members()),
            'popular-books' => array_map(static fn (array $book): array => [$book['title'], $book['borrowCount']], $this->popularBooks()),
            default => null,
        };
    }

    private function inventoryCsvRows(): array
    {
        $rows = $this->pdo->query(
            'SELECT categories.name, COUNT(books.id) AS count
             FROM categories
             LEFT JOIN books ON books.category_id = categories.id
             GROUP BY categories.id, categories.name
             ORDER BY categories.name ASC'
        )->fetchAll();

        return array_map(static fn (array $row): array => [$row['name'], (int) $row['count']], $rows);
    }

    private function countTransactions(string $status): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE status = ?');
        $statement->execute([$status]);
        return (int) $statement->fetchColumn();
    }

    private function money(string $sql): string
    {
        return number_format((float) $this->pdo->query($sql)->fetchColumn(), 2, '.', '');
    }
}

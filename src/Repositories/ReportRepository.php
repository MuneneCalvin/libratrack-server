<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
use PDO;

final class ReportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function activeBorrows(?string $q, Pagination $pagination): array
    {
        [$where, $params] = $this->activeBorrowsWhere($q);

        $countSql = "SELECT COUNT(*)
            FROM transaction_items
            JOIN transactions ON transactions.id = transaction_items.transaction_id
            JOIN books ON books.id = transaction_items.book_id
            JOIN members ON members.id = transactions.member_id
            {$where}";
        $countStatement = $this->pdo->prepare($countSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = "SELECT
                transaction_items.id AS item_id,
                books.id AS book_id, books.title AS book_title, books.author AS book_author, books.isbn AS book_isbn, books.cover_url AS book_cover_url,
                members.id AS member_id, members.full_name AS member_name, members.membership_number AS membership_number,
                transactions.borrowed_at, transactions.due_date
            FROM transaction_items
            JOIN transactions ON transactions.id = transaction_items.transaction_id
            JOIN books ON books.id = transaction_items.book_id
            JOIN members ON members.id = transactions.member_id
            {$where}
            ORDER BY transactions.due_date ASC
            LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = array_map(self::toActiveBorrowRow(...), $statement->fetchAll());

        return ['rows' => $rows, 'total' => $total];
    }

    private function activeBorrowsCsvRows(): array
    {
        [$where, $params] = $this->activeBorrowsWhere(null);

        $sql = "SELECT
                books.title AS book_title, books.author AS book_author, books.isbn AS book_isbn,
                members.full_name AS member_name, members.membership_number AS membership_number,
                transactions.borrowed_at, transactions.due_date
            FROM transaction_items
            JOIN transactions ON transactions.id = transaction_items.transaction_id
            JOIN books ON books.id = transaction_items.book_id
            JOIN members ON members.id = transactions.member_id
            {$where}
            ORDER BY transactions.due_date ASC";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(static fn (array $row): array => [
            $row['book_title'],
            $row['book_author'],
            $row['book_isbn'],
            $row['member_name'],
            $row['membership_number'],
            $row['borrowed_at'],
            $row['due_date'],
        ], $statement->fetchAll());
    }

    private static function toActiveBorrowRow(array $row): array
    {
        return [
            'itemId' => (int) $row['item_id'],
            'bookId' => (int) $row['book_id'],
            'bookTitle' => $row['book_title'],
            'bookAuthor' => $row['book_author'],
            'bookIsbn' => $row['book_isbn'],
            'bookCoverUrl' => $row['book_cover_url'],
            'memberId' => (int) $row['member_id'],
            'memberName' => $row['member_name'],
            'membershipNumber' => $row['membership_number'],
            'borrowedAt' => (new \DateTimeImmutable($row['borrowed_at']))->format(\DateTimeInterface::ATOM),
            'dueDate' => (new \DateTimeImmutable($row['due_date']))->format(\DateTimeInterface::ATOM),
        ];
    }

    private function activeBorrowsWhere(?string $q): array
    {
        $clauses = ["transaction_items.returned_at IS NULL", "transactions.status = 'ACTIVE'"];
        $params = [];

        if (!empty($q)) {
            $clauses[] = '(books.title LIKE :q_title OR members.full_name LIKE :q_member OR members.membership_number LIKE :q_membership)';
            $needle = '%' . $q . '%';
            $params[':q_title'] = $needle;
            $params[':q_member'] = $needle;
            $params[':q_membership'] = $needle;
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    public function summary(): array
    {
        $totalBooks = (int) $this->pdo->query('SELECT COUNT(*) FROM books WHERE deleted_at IS NULL')->fetchColumn();
        $copies = $this->pdo->query('SELECT COALESCE(SUM(total_copies), 0) AS total_copies, COALESCE(SUM(available_copies), 0) AS available_copies FROM books WHERE deleted_at IS NULL')->fetch();
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
             LEFT JOIN books ON books.category_id = categories.id AND books.deleted_at IS NULL
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
             WHERE books.deleted_at IS NULL
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
            'active-borrows' => $this->activeBorrowsCsvRows(),
            default => null,
        };
    }

    private function inventoryCsvRows(): array
    {
        $rows = $this->pdo->query(
            'SELECT categories.name, COUNT(books.id) AS count
             FROM categories
             LEFT JOIN books ON books.category_id = categories.id AND books.deleted_at IS NULL
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

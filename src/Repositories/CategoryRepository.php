<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(bool $onlyWithBooks, Pagination $pagination): array
    {
        $having = $onlyWithBooks ? 'HAVING COUNT(books.id) > 0' : '';

        $countSql = "SELECT COUNT(*) FROM (
            SELECT categories.id
            FROM categories
            LEFT JOIN books ON books.category_id = categories.id
            GROUP BY categories.id
            {$having}
        ) AS counted";
        $total = (int) $this->pdo->query($countSql)->fetchColumn();

        $sql = "SELECT categories.id, categories.name, COUNT(books.id) AS book_count
                FROM categories
                LEFT JOIN books ON books.category_id = categories.id
                GROUP BY categories.id, categories.name
                {$having}
                ORDER BY categories.name ASC
                LIMIT :limit OFFSET :offset";
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':limit', $pagination->limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $pagination->offset, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT categories.id, categories.name, COUNT(books.id) AS book_count
             FROM categories
             LEFT JOIN books ON books.category_id = categories.id
             WHERE categories.id = ?
             GROUP BY categories.id, categories.name'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM categories WHERE name = ?');
        $statement->execute([$name]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function create(string $name): int
    {
        $statement = $this->pdo->prepare('INSERT INTO categories (name) VALUES (?)');
        $statement->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE categories SET name = ? WHERE id = ?');
        $statement->execute([$name, $id]);
    }

    public function countBooks(int $id): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM books WHERE category_id = ?');
        $statement->execute([$id]);
        return (int) $statement->fetchColumn();
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM categories WHERE id = ?');
        $statement->execute([$id]);
    }
}

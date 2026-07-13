<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use LibraTrack\Core\Pagination;
use PDO;

final class BookRepository
{
    private const SORT_CLAUSES = [
        'rating' => 'rating_average IS NULL, rating_average DESC, rating_count DESC, created_at DESC',
        'most_read' => 'already_read_count DESC, rating_count DESC, created_at DESC',
        'popular' => '(want_to_read_count + currently_reading_count + already_read_count) DESC, rating_count DESC, created_at DESC',
        'title' => 'title ASC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(array $filters, string $sort, Pagination $pagination): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $orderBy = self::SORT_CLAUSES[$sort] ?? 'created_at DESC';

        $countSql = "SELECT COUNT(*) FROM books {$where}";
        $countStatement = $this->pdo->prepare($countSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = "SELECT books.*, categories.name AS category_name
                FROM books
                JOIN categories ON categories.id = books.category_id
                {$where}
                ORDER BY {$orderBy}
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
            'SELECT books.*, categories.name AS category_name
             FROM books
             JOIN categories ON categories.id = books.category_id
             WHERE books.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findByIsbn(string $isbn): ?array
    {
        $statement = $this->pdo->prepare('SELECT id FROM books WHERE isbn = ?');
        $statement->execute([$isbn]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO books (
                category_id, title, author, isbn, total_copies, available_copies,
                publisher, published_year, cover_url, openlibrary_work_key, synopsis,
                subjects, language_codes, edition_count, rating_average, rating_count,
                want_to_read_count, currently_reading_count, already_read_count
            ) VALUES (
                :category_id, :title, :author, :isbn, :total_copies, :available_copies,
                :publisher, :published_year, :cover_url, :openlibrary_work_key, :synopsis,
                :subjects, :language_codes, :edition_count, :rating_average, :rating_count,
                :want_to_read_count, :currently_reading_count, :already_read_count
            )'
        );
        $statement->execute($this->toRow($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $row = $this->toRow($data);
        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = :{$column}", array_keys($row)));
        $row['id'] = $id;

        $statement = $this->pdo->prepare("UPDATE books SET {$assignments} WHERE id = :id");
        $statement->execute($row);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM books WHERE id = ?');
        $statement->execute([$id]);
    }

    private function toRow(array $data): array
    {
        return [
            'category_id' => $data['categoryId'],
            'title' => $data['title'],
            'author' => $data['author'],
            'isbn' => $data['isbn'],
            'total_copies' => $data['totalCopies'],
            'available_copies' => $data['availableCopies'],
            'publisher' => $data['publisher'] ?? null,
            'published_year' => $data['publishedYear'] ?? null,
            'cover_url' => $data['coverUrl'] ?? null,
            'openlibrary_work_key' => $data['openLibraryWorkKey'] ?? null,
            'synopsis' => $data['synopsis'] ?? null,
            'subjects' => json_encode($data['subjects'] ?? [], JSON_THROW_ON_ERROR),
            'language_codes' => json_encode($data['languageCodes'] ?? [], JSON_THROW_ON_ERROR),
            'edition_count' => $data['editionCount'] ?? 0,
            'rating_average' => $data['ratingAverage'] ?? null,
            'rating_count' => $data['ratingCount'] ?? 0,
            'want_to_read_count' => $data['wantToReadCount'] ?? 0,
            'currently_reading_count' => $data['currentlyReadingCount'] ?? 0,
            'already_read_count' => $data['alreadyReadCount'] ?? 0,
        ];
    }

    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['q'])) {
            $clauses[] = '(title LIKE :q_title OR author LIKE :q_author OR isbn LIKE :q_isbn)';
            $needle = '%' . $filters['q'] . '%';
            $params[':q_title'] = $needle;
            $params[':q_author'] = $needle;
            $params[':q_isbn'] = $needle;
        }
        if (!empty($filters['categoryId'])) {
            $clauses[] = 'category_id = :category_id';
            $params[':category_id'] = $filters['categoryId'];
        }
        if (array_key_exists('available', $filters) && $filters['available'] !== null) {
            $clauses[] = $filters['available'] ? 'available_copies > 0' : 'available_copies = 0';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}

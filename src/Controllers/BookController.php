<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\CategoryRepository;

final class BookController
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authMiddleware->authenticate($request);

        $filters = [
            'q' => $request->query['q'] ?? $request->query['search'] ?? null,
            'categoryId' => isset($request->query['category']) ? (int) $request->query['category'] : null,
            'available' => $this->parseTriBool($request->query['available'] ?? null),
        ];
        $sort = (string) ($request->query['sort'] ?? '');
        $pagination = Pagination::fromRequest($request);

        $result = $this->books->search($filters, $sort, $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function store(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $data = $this->validate($request->json ?? []);
        if ($this->books->findByIsbn($data['isbn'])) {
            throw new ValidationException('ISBN already exists');
        }

        $id = $this->books->create($data);

        return Response::success($this->toFrontend($this->books->find($id)), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $this->authMiddleware->authenticate($request);

        $book = $this->books->find((int) $params['id']);
        if ($book === null) {
            throw new ValidationException('Book not found', 404);
        }

        return Response::success($this->toFrontend($book));
    }

    public function update(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $id = (int) $params['id'];
        $existing = $this->books->find($id);
        if ($existing === null) {
            throw new ValidationException('Book not found', 404);
        }

        $merged = array_merge($this->fromFrontendRow($existing), $request->json ?? []);
        $data = $this->validate($merged);

        $isbnOwner = $this->books->findByIsbn($data['isbn']);
        if ($isbnOwner && (int) $isbnOwner['id'] !== $id) {
            throw new ValidationException('ISBN already exists');
        }

        $this->books->update($id, $data);

        return Response::success($this->toFrontend($this->books->find($id)));
    }

    public function destroy(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin']);

        $id = (int) $params['id'];
        if ($this->books->find($id) === null) {
            throw new ValidationException('Book not found', 404);
        }

        $this->books->delete($id);

        return new Response(['status' => 'success', 'data' => null], 204);
    }

    private function validate(array $data): array
    {
        foreach (['title', 'author', 'isbn', 'categoryId'] as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("{$field} is required");
            }
        }
        if ($this->categories->find((int) $data['categoryId']) === null) {
            throw new ValidationException('categoryId does not reference an existing category');
        }

        $data['totalCopies'] = (int) ($data['totalCopies'] ?? 1);
        $data['availableCopies'] = (int) ($data['availableCopies'] ?? 1);

        return $data;
    }

    private function parseTriBool(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        $lower = strtolower($value);
        if (in_array($lower, ['true', '1', 'yes'], true)) {
            return true;
        }
        if (in_array($lower, ['false', '0', 'no'], true)) {
            return false;
        }
        return null;
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'author' => $row['author'],
            'isbn' => $row['isbn'],
            'categoryId' => (int) $row['category_id'],
            'categoryName' => $row['category_name'],
            'totalCopies' => (int) $row['total_copies'],
            'availableCopies' => (int) $row['available_copies'],
            'publisher' => $row['publisher'],
            'publishedYear' => $row['published_year'] !== null ? (int) $row['published_year'] : null,
            'coverUrl' => $row['cover_url'],
            'openLibraryWorkKey' => $row['openlibrary_work_key'],
            'synopsis' => $row['synopsis'],
            'subjects' => json_decode($row['subjects'] ?? '[]', true, 512, JSON_THROW_ON_ERROR),
            'languageCodes' => json_decode($row['language_codes'] ?? '[]', true, 512, JSON_THROW_ON_ERROR),
            'editionCount' => (int) $row['edition_count'],
            'ratingAverage' => $row['rating_average'] !== null ? (float) $row['rating_average'] : null,
            'ratingCount' => (int) $row['rating_count'],
            'wantToReadCount' => (int) $row['want_to_read_count'],
            'currentlyReadingCount' => (int) $row['currently_reading_count'],
            'alreadyReadCount' => (int) $row['already_read_count'],
        ];
    }

    private function fromFrontendRow(array $row): array
    {
        return $this->toFrontend($row);
    }
}

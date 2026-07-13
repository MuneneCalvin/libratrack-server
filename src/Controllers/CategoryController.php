<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\CategoryRepository;

final class CategoryController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authMiddleware->authenticate($request);

        $onlyWithBooks = $this->parseBool($request->query['withBooks'] ?? $request->query['hasBooks'] ?? null);
        $pagination = Pagination::fromRequest($request);
        $result = $this->categories->list($onlyWithBooks, $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function store(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $name = trim((string) ($request->json['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('name is required');
        }
        if ($this->categories->findByName($name)) {
            throw new ValidationException('Category name already exists');
        }

        $id = $this->categories->create($name);
        $category = $this->categories->find($id);

        return Response::success($this->toFrontend($category), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $this->authMiddleware->authenticate($request);

        $category = $this->categories->find((int) $params['id']);
        if ($category === null) {
            throw new ValidationException('Category not found', 404);
        }

        return Response::success($this->toFrontend($category));
    }

    public function update(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $id = (int) $params['id'];
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new ValidationException('Category not found', 404);
        }

        $name = trim((string) ($request->json['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('name is required');
        }
        $existing = $this->categories->findByName($name);
        if ($existing && (int) $existing['id'] !== $id) {
            throw new ValidationException('Category name already exists');
        }

        $this->categories->update($id, $name);

        return Response::success($this->toFrontend($this->categories->find($id)));
    }

    public function destroy(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin']);

        $id = (int) $params['id'];
        $category = $this->categories->find($id);
        if ($category === null) {
            throw new ValidationException('Category not found', 404);
        }
        if ($this->categories->countBooks($id) > 0) {
            throw new ValidationException('Cannot delete category with existing books');
        }

        $this->categories->delete($id);

        return new Response(['status' => 'success', 'data' => null], 204);
    }

    private function parseBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        return in_array(strtolower($value), ['true', '1', 'yes'], true);
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'bookCount' => (int) $row['book_count'],
        ];
    }
}

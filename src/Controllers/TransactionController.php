<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\FineRepository;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\SettingsRepository;
use LibraTrack\Repositories\TransactionRepository;

final class TransactionController
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly FineRepository $fines,
        private readonly MemberRepository $members,
        private readonly BookRepository $books,
        private readonly SettingsRepository $settings,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $filters = [
            'status' => $request->query['status'] ?? null,
            'memberId' => isset($request->query['memberId']) ? (int) $request->query['memberId'] : null,
            'bookId' => isset($request->query['bookId']) ? (int) $request->query['bookId'] : null,
            'q' => $request->query['q'] ?? null,
        ];
        $pagination = Pagination::fromRequest($request);
        $result = $this->transactions->search($filters, $pagination);

        $withItems = array_map(fn (array $row): array => $this->transactions->findWithItems((int) $row['id']), $result['rows']);

        return Response::paginated(array_map($this->toFrontend(...), $withItems), $pagination->meta($result['total']));
    }

    public function store(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $data = $request->json ?? [];
        if (empty($data['memberId']) || empty($data['bookIds']) || !is_array($data['bookIds'])) {
            throw new ValidationException('memberId and bookIds are required');
        }
        $memberId = (int) $data['memberId'];
        $bookIds = array_map('intval', $data['bookIds']);

        if ($this->members->find($memberId) === null) {
            throw new ValidationException('Member not found', 404);
        }

        $settings = $this->settings->all();
        $maxBooks = $settings['maxBooksPerMember'];
        $activeCount = $this->members->countActiveBorrows($memberId);

        if ($activeCount + count($bookIds) > $maxBooks) {
            $remainingSlots = max($maxBooks - $activeCount, 0);
            throw new ValidationException(
                "Member cannot borrow more than {$maxBooks} books at once",
                400,
                ['activeBorrowCount' => $activeCount, 'maxBooks' => $maxBooks, 'remainingSlots' => $remainingSlots]
            );
        }

        foreach ($bookIds as $bookId) {
            $book = $this->books->find($bookId);
            if ($book === null) {
                throw new ValidationException('Book not found', 404);
            }
            if ((int) $book['available_copies'] < 1) {
                throw new ValidationException("Book \"{$book['title']}\" is not available");
            }
        }

        $dueDate = (new DateTimeImmutable())->add(new DateInterval("P{$settings['borrowDays']}D"));
        $transactionId = $this->transactions->create($memberId, $bookIds, $dueDate);

        return Response::success($this->toFrontend($this->transactions->findWithItems($transactionId)), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $transaction = $this->transactions->findWithItems((int) $params['id']);
        if ($transaction === null) {
            throw new ValidationException('Transaction not found', 404);
        }

        return Response::success($this->toFrontend($transaction));
    }

    public function returnItems(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $id = (int) $params['id'];
        $transaction = $this->transactions->find($id);
        if ($transaction === null) {
            throw new ValidationException('Transaction not found', 404);
        }

        $unreturned = $this->transactions->unreturnedItems($id);
        if ($transaction['status'] === 'RETURNED' || $unreturned === []) {
            throw new ValidationException('Transaction already returned');
        }

        $requestedIds = $request->json['itemIds'] ?? null;
        if ($requestedIds !== null) {
            if (!is_array($requestedIds) || $requestedIds === []) {
                throw new ValidationException('itemIds must be a non-empty array');
            }
            $requestedIds = array_map('intval', $requestedIds);
            $unreturned = array_values(array_filter(
                $unreturned,
                static fn (array $item): bool => in_array((int) $item['id'], $requestedIds, true)
            ));
            if (count($unreturned) !== count(array_unique($requestedIds))) {
                throw new ValidationException('One or more itemIds are invalid or already returned');
            }
        }

        $this->transactions->returnItems($id, $unreturned);

        $now = new DateTimeImmutable();
        $dueDate = new DateTimeImmutable($transaction['due_date']);
        if ($now > $dueDate) {
            $today = new DateTimeImmutable($now->format('Y-m-d'));
            $dueDay = new DateTimeImmutable($dueDate->format('Y-m-d'));
            $daysLate = max(1, $today->diff($dueDay)->days);
            $fineRate = $this->settings->all()['fineRatePerDay'];
            $this->fines->createForOverdueReturn(
                (int) $transaction['member_id'],
                $id,
                $daysLate * $fineRate,
                "Book returned {$daysLate} day(s) late"
            );
        }

        return Response::success($this->toFrontend($this->transactions->findWithItems($id)));
    }

    public function forMember(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $memberId = (int) $params['id'];
        $member = $this->members->find($memberId);
        if ($member === null) {
            throw new ValidationException('Member not found', 404);
        }
        $isStaff = in_array($payload['role'], ['admin', 'librarian'], true);
        if (!$isStaff && (int) $member['user_id'] !== (int) $payload['sub']) {
            throw new ValidationException('Forbidden', 403);
        }

        $rows = $this->transactions->findByMember($memberId, $request->query['status'] ?? null);
        $withItems = array_map(fn (array $row): array => $this->transactions->findWithItems((int) $row['id']), $rows);

        return Response::success(array_map($this->toFrontend(...), $withItems));
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'memberId' => (int) $row['member_id'],
            'memberName' => $row['member_full_name'] ?? null,
            'borrowedAt' => (new DateTimeImmutable($row['borrowed_at']))->format(DateTimeInterface::ATOM),
            'dueDate' => (new DateTimeImmutable($row['due_date']))->format(DateTimeInterface::ATOM),
            'returnedAt' => $row['returned_at'] !== null ? (new DateTimeImmutable($row['returned_at']))->format(DateTimeInterface::ATOM) : null,
            'status' => $row['status'],
            'items' => array_map(static fn (array $item): array => [
                'id' => (int) $item['item_id'],
                'book' => BookRepository::toFrontend($item),
                'returnedAt' => $item['item_returned_at'] !== null ? (new DateTimeImmutable($item['item_returned_at']))->format(DateTimeInterface::ATOM) : null,
            ], $row['items'] ?? []),
        ];
    }
}

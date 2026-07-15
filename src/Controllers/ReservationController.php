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
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\ReservationRepository;
use LibraTrack\Repositories\SettingsRepository;
use LibraTrack\Services\BorrowingService;

final class ReservationController
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly MemberRepository $members,
        private readonly BookRepository $books,
        private readonly SettingsRepository $settings,
        private readonly BorrowingService $borrowing,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $pagination = Pagination::fromRequest($request);
        $result = $this->reservations->list($pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function store(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);

        $data = $request->json ?? [];
        if (empty($data['bookId'])) {
            throw new ValidationException('bookId is required');
        }
        $bookId = (int) $data['bookId'];
        if ($this->books->find($bookId) === null) {
            throw new ValidationException('Book not found', 404);
        }

        $isStaff = in_array($payload['role'], ['admin', 'librarian'], true);
        if ($isStaff) {
            if (empty($data['memberId'])) {
                throw new ValidationException('memberId is required for admin/librarian');
            }
            $member = $this->members->find((int) $data['memberId']);
        } else {
            $member = $this->members->findByUserId((int) $payload['sub']);
        }
        if ($member === null) {
            throw new ValidationException('Member not found', 404);
        }

        $expiryDays = $this->settings->all()['reservationExpiryDays'];
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval("P{$expiryDays}D"));
        $id = $this->reservations->create((int) $member['id'], $bookId, $expiresAt);

        return Response::success($this->toFrontend($this->reservations->find($id)), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $this->authMiddleware->authenticate($request);

        $reservation = $this->reservations->find((int) $params['id']);
        if ($reservation === null) {
            throw new ValidationException('Reservation not found', 404);
        }

        return Response::success($this->toFrontend($reservation));
    }

    public function cancel(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);

        $id = (int) $params['id'];
        $reservation = $this->reservations->find($id);
        if ($reservation === null) {
            throw new ValidationException('Reservation not found', 404);
        }

        $isStaff = in_array($payload['role'], ['admin', 'librarian'], true);
        if (!$isStaff) {
            $member = $this->members->findByUserId((int) $payload['sub']);
            if ($member === null || (int) $member['id'] !== (int) $reservation['member_id']) {
                throw new ValidationException('Forbidden', 403);
            }
        }

        $this->reservations->updateStatus($id, 'CANCELLED');

        return Response::success($this->toFrontend($this->reservations->find($id)));
    }

    public function fulfill(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $id = (int) $params['id'];
        $reservation = $this->reservations->find($id);
        if ($reservation === null) {
            throw new ValidationException('Reservation not found', 404);
        }
        if ($reservation['status'] !== 'PENDING') {
            throw new ValidationException('Reservation is not pending');
        }

        $this->borrowing->issue((int) $reservation['member_id'], [(int) $reservation['book_id']]);
        $this->reservations->updateStatus($id, 'FULFILLED');

        return Response::success($this->toFrontend($this->reservations->find($id)));
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

        $rows = $this->reservations->findByMember($memberId, $request->query['status'] ?? null);

        return Response::success(array_map($this->toFrontend(...), $rows));
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'memberId' => (int) $row['member_id'],
            'memberName' => $row['member_full_name'],
            'bookId' => (int) $row['book_id'],
            'bookTitle' => $row['book_title'],
            'bookAuthor' => $row['book_author'],
            'bookCoverUrl' => $row['book_cover_url'],
            'reservedAt' => (new DateTimeImmutable($row['reserved_at']))->format(DateTimeInterface::ATOM),
            'expiresAt' => (new DateTimeImmutable($row['expires_at']))->format(DateTimeInterface::ATOM),
            'status' => $row['status'],
        ];
    }
}

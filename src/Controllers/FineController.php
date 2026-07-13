<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use DateTimeImmutable;
use DateTimeInterface;
use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\FineRepository;
use LibraTrack\Repositories\MemberRepository;

final class FineController
{
    public function __construct(
        private readonly FineRepository $fines,
        private readonly MemberRepository $members,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $filters = [
            'memberId' => isset($request->query['memberId']) ? (int) $request->query['memberId'] : null,
            'status' => $request->query['status'] ?? null,
            'q' => $request->query['q'] ?? null,
            'isPaid' => isset($request->query['isPaid']) ? strtolower((string) $request->query['isPaid']) === 'true' : null,
        ];
        $pagination = Pagination::fromRequest($request);
        $result = $this->fines->search($filters, $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function show(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $fine = $this->fines->find((int) $params['id']);
        if ($fine === null) {
            throw new ValidationException('Fine not found', 404);
        }

        return Response::success($this->toFrontend($fine));
    }

    public function pay(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $id = (int) $params['id'];
        if ($this->fines->find($id) === null) {
            throw new ValidationException('Fine not found', 404);
        }

        $this->fines->markPaid($id);

        return Response::success($this->toFrontend($this->fines->find($id)));
    }

    public function waive(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin']);

        $id = (int) $params['id'];
        if ($this->fines->find($id) === null) {
            throw new ValidationException('Fine not found', 404);
        }

        $data = $request->json ?? [];
        $note = $data['waivedNote'] ?? $data['note'] ?? null;
        $this->fines->markWaived($id, $note !== null ? (string) $note : null);

        return Response::success($this->toFrontend($this->fines->find($id)));
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

        $isPaid = isset($request->query['isPaid']) ? strtolower((string) $request->query['isPaid']) === 'true' : null;
        $rows = $this->fines->findByMember($memberId, $isPaid);

        return Response::success(array_map($this->toFrontend(...), $rows));
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'memberId' => (int) $row['member_id'],
            'memberName' => $row['member_full_name'],
            'transactionId' => $row['transaction_id'] !== null ? (int) $row['transaction_id'] : null,
            'amount' => (float) $row['amount'],
            'reason' => $row['reason'],
            'isPaid' => $row['status'] === 'paid',
            'isWaived' => $row['status'] === 'waived',
            'waivedNote' => $row['waived_note'],
            'createdAt' => (new DateTimeImmutable($row['created_at']))->format(DateTimeInterface::ATOM),
        ];
    }
}

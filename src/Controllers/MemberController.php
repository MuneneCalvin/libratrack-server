<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\UserRepository;
use LibraTrack\Services\PasswordService;

final class MemberController
{
    public function __construct(
        private readonly MemberRepository $members,
        private readonly UserRepository $users,
        private readonly PasswordService $passwords,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $pagination = Pagination::fromRequest($request);
        $result = $this->members->search($request->query['q'] ?? null, $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function store(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        $data = $request->json ?? [];
        foreach (['email', 'password', 'fullName'] as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("{$field} is required");
            }
        }
        if ($this->users->findByEmail((string) $data['email'])) {
            throw new ValidationException('Email already exists');
        }

        $userId = $this->users->createUser(
            (string) $data['email'],
            $this->passwords->hash((string) $data['password']),
            'member',
            true
        );
        $memberId = $this->members->createForUser($userId, (string) $data['fullName'], $data['phone'] ?? null, $data['address'] ?? null);

        return Response::success($this->toFrontend($this->members->find($memberId)), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $member = $this->requireSelfOrStaff($payload, (int) $params['id']);

        return Response::success($this->toFrontend($member));
    }

    public function update(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $id = (int) $params['id'];
        $member = $this->requireSelfOrStaff($payload, $id);
        $isStaff = in_array($payload['role'], ['admin', 'librarian'], true);

        $data = $request->json ?? [];
        $fields = [];

        if (array_key_exists('fullName', $data)) {
            $fields['full_name'] = (string) $data['fullName'];
        }
        if (array_key_exists('phone', $data)) {
            $fields['phone'] = $data['phone'];
        }
        if (array_key_exists('address', $data)) {
            $fields['address'] = $data['address'];
        }
        if ($isStaff && array_key_exists('membershipNumber', $data)) {
            $number = (string) $data['membershipNumber'];
            $existing = $this->members->findByMembershipNumber($number);
            if ($existing && (int) $existing['id'] !== $id) {
                throw new ValidationException('Membership number already exists');
            }
            $fields['membership_number'] = $number;
        }

        if ($fields !== []) {
            $this->members->updateProfile($id, $fields);
        }
        if ($isStaff && array_key_exists('isActive', $data)) {
            $this->users->setActive((int) $member['user_id'], (bool) $data['isActive']);
        }
        if (array_key_exists('email', $data)) {
            $email = (string) $data['email'];
            $existingUser = $this->users->findByEmail($email);
            if ($existingUser && (int) $existingUser['id'] !== (int) $member['user_id']) {
                throw new ValidationException('Email already exists');
            }
            $this->users->updateEmail((int) $member['user_id'], $email);
        }

        return Response::success($this->toFrontend($this->members->find($id)));
    }

    public function destroy(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin']);

        $id = (int) $params['id'];
        if ($this->members->find($id) === null) {
            throw new ValidationException('Member not found', 404);
        }

        $this->members->deleteCascade($id);

        return new Response(['status' => 'success', 'data' => null], 204);
    }

    private function requireSelfOrStaff(array $payload, int $memberId): array
    {
        $member = $this->members->find($memberId);
        if ($member === null) {
            throw new ValidationException('Member not found', 404);
        }
        $isStaff = in_array($payload['role'], ['admin', 'librarian'], true);
        $isSelf = (int) $member['user_id'] === (int) $payload['sub'];
        if (!$isStaff && !$isSelf) {
            throw new ValidationException('Forbidden', 403);
        }

        return $member;
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'fullName' => $row['full_name'],
            'phone' => $row['phone'],
            'address' => $row['address'],
            'membershipNumber' => $row['membership_number'],
            'joinedAt' => (new \DateTimeImmutable($row['joined_at']))->format(\DateTimeInterface::ATOM),
            'isActive' => (bool) $row['is_active'],
        ];
    }
}

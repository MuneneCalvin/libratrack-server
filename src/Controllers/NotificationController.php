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
use LibraTrack\Repositories\NotificationRepository;

final class NotificationController
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function index(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $pagination = Pagination::fromRequest($request);
        $result = $this->notifications->searchForUser((int) $payload['sub'], $pagination);

        return Response::paginated(array_map($this->toFrontend(...), $result['rows']), $pagination->meta($result['total']));
    }

    public function markRead(Request $request, array $params): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $notification = $this->notifications->findForUser((int) $params['id'], (int) $payload['sub']);
        if ($notification === null) {
            throw new ValidationException('Notification not found', 404);
        }

        $this->notifications->markRead((int) $params['id']);

        return Response::success($this->toFrontend($this->notifications->findForUser((int) $params['id'], (int) $payload['sub'])));
    }

    public function markAllRead(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->notifications->markAllReadForUser((int) $payload['sub']);

        return Response::success(['message' => 'All notifications marked as read']);
    }

    public function remind(Request $request): Response
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);

        return Response::success(['sent' => $this->notifications->sendOverdueReminders()]);
    }

    private function toFrontend(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'type' => $row['type'],
            'isRead' => (bool) $row['is_read'],
            'createdAt' => (new DateTimeImmutable($row['created_at']))->format(DateTimeInterface::ATOM),
        ];
    }
}

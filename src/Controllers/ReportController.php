<?php

declare(strict_types=1);

namespace LibraTrack\Controllers;

use LibraTrack\Core\Request;
use LibraTrack\Core\Response;
use LibraTrack\Core\ValidationException;
use LibraTrack\Middleware\AuthMiddleware;
use LibraTrack\Middleware\RoleMiddleware;
use LibraTrack\Repositories\ReportRepository;

final class ReportController
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    public function summary(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->summary());
    }

    public function borrowing(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->borrowing());
    }

    public function inventory(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->inventory());
    }

    public function fines(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->fines());
    }

    public function overdue(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->overdue());
    }

    public function popularBooks(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->popularBooks());
    }

    public function members(Request $request): Response
    {
        $this->authorizeStaff($request);
        return Response::success($this->reports->members());
    }

    public function export(Request $request): Response
    {
        $this->authorizeStaff($request);

        $type = (string) (($request->json['type'] ?? 'csv'));
        $report = (string) (($request->json['report'] ?? 'borrowing'));
        if ($type !== 'csv') {
            throw new ValidationException('Only CSV export is supported');
        }

        $rows = $this->reports->csvRows($report);
        if ($rows === null) {
            throw new ValidationException('Unknown report');
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['metric', 'value'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return Response::csv($body, "{$report}.csv");
    }

    private function authorizeStaff(Request $request): void
    {
        $payload = $this->authMiddleware->authenticate($request);
        $this->roleMiddleware->authorize($payload, ['admin', 'librarian']);
    }
}

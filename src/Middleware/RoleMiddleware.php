<?php

declare(strict_types=1);

namespace LibraTrack\Middleware;

use LibraTrack\Core\ValidationException;

final class RoleMiddleware
{
    public function authorize(array $payload, array $allowedRoles): void
    {
        $role = $payload['role'] ?? null;
        if (!in_array($role, $allowedRoles, true)) {
            throw new ValidationException('Forbidden', 403);
        }
    }
}

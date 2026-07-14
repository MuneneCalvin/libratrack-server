<?php

declare(strict_types=1);

namespace LibraTrack\Middleware;

use LibraTrack\Core\Request;
use LibraTrack\Core\ValidationException;
use LibraTrack\Services\TokenService;

final class AuthMiddleware
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function authenticate(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ValidationException('Authentication required', 401);
        }

        return $this->tokens->decodeAccessToken($token);
    }
}

<?php

declare(strict_types=1);

namespace LibraTrack\Services;

use DateInterval;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LibraTrack\Core\Config;
use LibraTrack\Core\ValidationException;

final class TokenService
{
    public function __construct(private readonly Config $config)
    {
    }

    public function issueAccessToken(array $user): string
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->add(new DateInterval('PT' . $this->config->get('JWT_ACCESS_TTL_MINUTES', '15') . 'M'));

        return JWT::encode([
            'sub' => (string) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
        ], $this->config->get('JWT_SECRET'), 'HS256');
    }

    public function decodeAccessToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->config->get('JWT_SECRET'), 'HS256'));
        } catch (\UnexpectedValueException) {
            throw new ValidationException('Invalid or expired token', 401);
        }

        return json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function newRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function refreshExpiresAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->add(new DateInterval('P' . $this->config->get('JWT_REFRESH_TTL_DAYS', '7') . 'D'));
    }
}

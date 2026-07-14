<?php

declare(strict_types=1);

namespace LibraTrack\Services;

use LibraTrack\Core\ValidationException;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\RefreshTokenRepository;
use LibraTrack\Repositories\UserRepository;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly MemberRepository $members,
        private readonly PasswordService $passwords,
        private readonly TokenService $tokens
    ) {
    }

    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if (!$user || !$this->passwords->verify($password, $user['password_hash'])) {
            throw new ValidationException('Invalid email or password', 401);
        }
        if ((int) $user['is_active'] !== 1) {
            throw new ValidationException('Account is inactive', 403);
        }

        return $this->issueSession($user);
    }

    public function signup(array $payload): array
    {
        foreach (['email', 'password', 'fullName'] as $field) {
            if (empty($payload[$field])) {
                throw new ValidationException("{$field} is required");
            }
        }
        if ($this->users->findByEmail((string) $payload['email'])) {
            throw new ValidationException('Email already exists', 400);
        }

        $userId = $this->users->createUser(
            (string) $payload['email'],
            $this->passwords->hash((string) $payload['password']),
            'member',
            false
        );
        $this->members->createForUser(
            $userId,
            (string) $payload['fullName'],
            $payload['phone'] ?? null,
            $payload['address'] ?? null
        );

        $user = $this->users->findById($userId);
        $session = $this->issueSession($user);
        $session['user'] = $this->frontendUser($user);
        return $session;
    }

    public function refresh(string $refreshToken): array
    {
        $hash = $this->tokens->hashRefreshToken($refreshToken);
        $stored = $this->refreshTokens->findActiveByHash($hash);
        if (!$stored) {
            throw new ValidationException('Invalid refresh token', 401);
        }

        $this->refreshTokens->revokeByHash($hash);
        $user = $this->users->findById((int) $stored['user_id']);
        if (!$user || (int) $user['is_active'] !== 1) {
            throw new ValidationException('Account is inactive', 403);
        }

        return $this->issueSession($user);
    }

    public function logout(string $refreshToken): void
    {
        $this->refreshTokens->revokeByHash($this->tokens->hashRefreshToken($refreshToken));
    }

    public function currentUser(int $userId): array
    {
        $user = $this->users->findById($userId);
        if (!$user) {
            throw new ValidationException('User not found', 404);
        }
        return $this->frontendUser($user);
    }

    public function updateEmail(int $userId, string $email): array
    {
        $this->users->updateEmail($userId, $email);
        return $this->currentUser($userId);
    }

    public function changePassword(int $userId, string $password): void
    {
        if (strlen($password) < 8) {
            throw new ValidationException('Password must be at least 8 characters');
        }
        $this->users->updatePassword($userId, $this->passwords->hash($password));
    }

    private function issueSession(array $user): array
    {
        $accessToken = $this->tokens->issueAccessToken($user);
        $refreshToken = $this->tokens->newRefreshToken();
        $this->refreshTokens->store(
            (int) $user['id'],
            $this->tokens->hashRefreshToken($refreshToken),
            $this->tokens->refreshExpiresAt()
        );

        return ['accessToken' => $accessToken, 'refreshToken' => $refreshToken];
    }

    private function frontendUser(array $user): array
    {
        $member = $this->members->findByUserId((int) $user['id']);
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'memberId' => $member ? (int) $member['id'] : null,
            'mustChangePassword' => (bool) $user['must_change_password'],
        ];
    }
}

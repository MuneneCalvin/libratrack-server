<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.*, roles.name AS role
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.email = ?'
        );
        $statement->execute([$email]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.*, roles.name AS role
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function createUser(string $email, string $passwordHash, string $role, bool $mustChangePassword): int
    {
        $roleStatement = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $roleStatement->execute([$role]);
        $roleId = (int) $roleStatement->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO users (role_id, email, password_hash, must_change_password, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $statement->execute([$roleId, $email, $passwordHash, $mustChangePassword ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateEmail(int $id, string $email): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
        $statement->execute([$email, $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
        );
        $statement->execute([$passwordHash, $id]);
    }
}

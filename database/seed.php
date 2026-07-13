<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Services\PasswordService;

$root = dirname(__DIR__);
$pdo = Database::fromConfig(Config::fromProjectRoot($root))->pdo();
$passwords = new PasswordService();

$roles = ['admin', 'librarian', 'member'];
foreach ($roles as $role) {
    $statement = $pdo->prepare('INSERT IGNORE INTO roles (name) VALUES (?)');
    $statement->execute([$role]);
}

$roleId = function (string $role) use ($pdo): int {
    $statement = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
    $statement->execute([$role]);
    return (int) $statement->fetchColumn();
};

$createUser = function (
    string $email,
    string $password,
    string $role,
    bool $mustChangePassword = false
) use ($pdo, $passwords, $roleId): int {
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $statement->execute([$email]);
    $existing = $statement->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (role_id, email, password_hash, must_change_password, is_active)
         VALUES (?, ?, ?, ?, 1)'
    );
    $statement->execute([
        $roleId($role),
        $email,
        $passwords->hash($password),
        $mustChangePassword ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
};

$adminId = $createUser('admin@libratrack.com', 'Admin@1234', 'admin');
$librarianId = $createUser('librarian@libratrack.com', 'Librarian@1234', 'librarian');
$aliceUserId = $createUser('alice@libratrack.com', 'Member@1234', 'member');
$bobUserId = $createUser('bob@libratrack.com', 'Member@1234', 'member');

$members = [
    [$aliceUserId, 'Alice Johnson', '+254 712 000 001', 'Nairobi, Kenya', 'MEM-A3F2B1'],
    [$bobUserId, 'Bob Smith', '+254 712 000 002', 'Nairobi, Kenya', 'MEM-B4C3D2'],
];

foreach ($members as [$userId, $name, $phone, $address, $membershipNumber]) {
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO members (user_id, full_name, phone, address, membership_number, joined_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $statement->execute([$userId, $name, $phone, $address, $membershipNumber]);
}

$settings = [
    'fine_rate_per_day' => '10',
    'borrow_days' => '14',
    'max_books_per_member' => '5',
    'reservation_expiry_days' => '3',
];

foreach ($settings as $key => $value) {
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([$key, $value]);
}

echo "Seeded roles, users, members, and settings\n";

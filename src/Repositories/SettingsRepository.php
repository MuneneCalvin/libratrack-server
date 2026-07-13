<?php

declare(strict_types=1);

namespace LibraTrack\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $values = [];
        foreach ($rows as $row) {
            $values[$row['setting_key']] = $row['setting_value'];
        }

        return [
            'fineRatePerDay' => (float) ($values['fine_rate_per_day'] ?? 10),
            'borrowDays' => (int) ($values['borrow_days'] ?? 14),
            'maxBooksPerMember' => (int) ($values['max_books_per_member'] ?? 5),
            'reservationExpiryDays' => (int) ($values['reservation_expiry_days'] ?? 3),
        ];
    }

    public function update(array $payload): array
    {
        $map = [
            'fineRatePerDay' => 'fine_rate_per_day',
            'borrowDays' => 'borrow_days',
            'maxBooksPerMember' => 'max_books_per_member',
            'reservationExpiryDays' => 'reservation_expiry_days',
        ];

        foreach ($map as $camel => $key) {
            if (!array_key_exists($camel, $payload)) {
                continue;
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $statement->execute([$key, (string) $payload[$camel]]);
        }

        return $this->all();
    }
}

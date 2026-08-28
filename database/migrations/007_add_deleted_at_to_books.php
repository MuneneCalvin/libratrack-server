<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE books ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER already_read_count',
    ],
    'down' => [
        'ALTER TABLE books DROP COLUMN deleted_at',
    ],
];

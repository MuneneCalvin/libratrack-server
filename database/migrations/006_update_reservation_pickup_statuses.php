<?php

declare(strict_types=1);

return [
    'up' => [
        "ALTER TABLE reservations MODIFY status ENUM('PENDING', 'FULFILLED', 'READY_FOR_PICKUP', 'BORROWED', 'CANCELLED', 'EXPIRED') NOT NULL DEFAULT 'PENDING'",
        "UPDATE reservations SET status = 'BORROWED' WHERE status = 'FULFILLED'",
        "ALTER TABLE reservations MODIFY status ENUM('PENDING', 'READY_FOR_PICKUP', 'BORROWED', 'CANCELLED', 'EXPIRED') NOT NULL DEFAULT 'PENDING'",
    ],
    'down' => [
        "ALTER TABLE reservations MODIFY status ENUM('PENDING', 'FULFILLED', 'READY_FOR_PICKUP', 'BORROWED', 'CANCELLED', 'EXPIRED') NOT NULL DEFAULT 'PENDING'",
        "UPDATE reservations SET status = 'FULFILLED' WHERE status = 'BORROWED'",
        "UPDATE reservations SET status = 'PENDING' WHERE status = 'READY_FOR_PICKUP'",
        "ALTER TABLE reservations MODIFY status ENUM('PENDING', 'FULFILLED', 'CANCELLED', 'EXPIRED') NOT NULL DEFAULT 'PENDING'",
    ],
];

<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            due_date DATETIME NOT NULL,
            returned_at DATETIME NULL,
            status ENUM('ACTIVE', 'RETURNED', 'OVERDUE') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_transactions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS transaction_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            book_id INT NOT NULL,
            returned_at DATETIME NULL,
            CONSTRAINT fk_transaction_items_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
            CONSTRAINT fk_transaction_items_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            transaction_id INT NULL,
            amount DECIMAL(10,2) NOT NULL,
            reason VARCHAR(255) NULL,
            status ENUM('unpaid', 'paid', 'waived') NOT NULL DEFAULT 'unpaid',
            paid_at DATETIME NULL,
            waived_at DATETIME NULL,
            waived_note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_fines_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT,
            CONSTRAINT fk_fines_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
            UNIQUE KEY uniq_fines_transaction (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS fines',
        'DROP TABLE IF EXISTS transaction_items',
        'DROP TABLE IF EXISTS transactions',
    ],
];

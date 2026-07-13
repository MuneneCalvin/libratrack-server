<?php

declare(strict_types=1);

return [
    'up' => [
        "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            title VARCHAR(500) NOT NULL,
            author VARCHAR(500) NOT NULL,
            isbn VARCHAR(20) NOT NULL UNIQUE,
            total_copies INT NOT NULL DEFAULT 1,
            available_copies INT NOT NULL DEFAULT 1,
            publisher VARCHAR(255) NULL,
            published_year INT NULL,
            cover_url VARCHAR(500) NULL,
            openlibrary_work_key VARCHAR(64) NULL,
            synopsis TEXT NULL,
            subjects JSON NULL,
            language_codes JSON NULL,
            edition_count INT UNSIGNED NOT NULL DEFAULT 0,
            rating_average FLOAT NULL,
            rating_count INT UNSIGNED NOT NULL DEFAULT 0,
            want_to_read_count INT UNSIGNED NOT NULL DEFAULT 0,
            currently_reading_count INT UNSIGNED NOT NULL DEFAULT 0,
            already_read_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_books_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
            INDEX idx_books_title (title(191)),
            INDEX idx_books_author (author(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'down' => [
        'DROP TABLE IF EXISTS books',
        'DROP TABLE IF EXISTS categories',
    ],
];

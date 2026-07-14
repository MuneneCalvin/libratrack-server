<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;

$root = dirname(__DIR__);
$config = Config::fromProjectRoot($root);
$pdo = Database::fromConfig($config)->pdo();
$direction = $argv[1] ?? 'up';

$files = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($files);

if ($direction === 'down') {
    $files = array_reverse($files);
}

foreach ($files as $file) {
    $name = basename($file);
    $migration = require $file;

    if ($direction === 'up') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS migration_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $statement = $pdo->prepare('SELECT COUNT(*) FROM migration_log WHERE migration = ?');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() > 0) {
            echo "Skipping {$name}\n";
            continue;
        }
    }

    foreach ($migration[$direction] as $sql) {
        $pdo->exec($sql);
    }

    if ($direction === 'up') {
        $statement = $pdo->prepare('INSERT INTO migration_log (migration) VALUES (?)');
        $statement->execute([$name]);
        echo "Applied {$name}\n";
    } else {
        echo "Rolled back {$name}\n";
    }
}

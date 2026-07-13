<?php

declare(strict_types=1);

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testBuildsPdoDsnFromConfig(): void
    {
        $config = new Config([
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'libratrack_test',
            'DB_USER' => 'root',
            'DB_PASSWORD' => '',
        ]);

        $database = Database::fromConfig($config);

        $this->assertSame('mysql:host=127.0.0.1;port=3306;dbname=libratrack_test;charset=utf8mb4', $database->dsn());
    }
}

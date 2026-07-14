<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use LibraTrack\Core\Config;
use LibraTrack\Core\Database;
use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\CategoryRepository;
use LibraTrack\Services\OpenLibraryClient;
use LibraTrack\Services\OpenLibraryImportService;

function parseIntOption(array $argv, string $name, int $default): int
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            $value = (int) substr($arg, strlen("--{$name}="));
            if ($value < 1) {
                fwrite(STDERR, "--{$name} must be greater than 0\n");
                exit(1);
            }
            return $value;
        }
    }

    return $default;
}

function hasFlag(array $argv, string $name): bool
{
    return in_array("--{$name}", $argv, true);
}

$limit = parseIntOption($argv, 'limit', 500);
$copies = parseIntOption($argv, 'copies', 50);
$pageSize = parseIntOption($argv, 'page-size', 50);
$timeout = parseIntOption($argv, 'timeout', 30);
$retries = parseIntOption($argv, 'retries', 5);
$skipWorkDetails = hasFlag($argv, 'skip-work-details');
$verifySsl = !hasFlag($argv, 'insecure');

$root = dirname(__DIR__);
$config = Config::fromProjectRoot($root);
$pdo = Database::fromConfig($config)->pdo();

$service = new OpenLibraryImportService(
    new OpenLibraryClient($timeout, $retries, $verifySsl),
    new CategoryRepository($pdo),
    new BookRepository($pdo)
);

$result = $service->run([
    'limit' => $limit,
    'copies' => $copies,
    'pageSize' => $pageSize,
    'skipWorkDetails' => $skipWorkDetails,
]);

echo "Imported: {$result['imported']}\n";
echo "Skipped duplicates: {$result['duplicates']}\n";
echo "Skipped invalid: {$result['invalid']}\n";
echo "Categories created: {$result['categoriesCreated']}\n";
echo "Work detail failures: {$result['detailFailures']}\n";
echo "Query failures: {$result['queryFailures']}\n";
foreach (array_slice($result['errors'], 0, 5) as $error) {
    echo "Open Library query failed: {$error}\n";
}
if (count($result['errors']) > 5) {
    echo 'Additional query failures: ' . (count($result['errors']) - 5) . "\n";
}

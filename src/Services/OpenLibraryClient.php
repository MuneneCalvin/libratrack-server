<?php

declare(strict_types=1);

namespace LibraTrack\Services;

final class OpenLibraryClient
{
    private const SEARCH_URL = 'https://openlibrary.org/search.json';
    private const BASE_URL = 'https://openlibrary.org';
    private const USER_AGENT = 'LibraTrack Open Library importer';
    private const SEARCH_FIELDS = 'key,title,author_name,isbn,publisher,first_publish_year,cover_i,subject,language,edition_count,ratings_average,ratings_count,want_to_read_count,currently_reading_count,already_read_count';

    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $retries = 5
    ) {
    }

    public function searchDocs(string $query, int $page, int $pageSize): array
    {
        $url = self::SEARCH_URL . '?' . http_build_query([
            'q' => $query,
            'page' => $page,
            'limit' => $pageSize,
            'fields' => self::SEARCH_FIELDS,
        ]);

        $payload = $this->getJsonWithRetries($url);
        $docs = $payload['docs'] ?? [];

        return array_values(array_filter($docs, static fn (mixed $doc): bool => is_array($doc)));
    }

    public function fetchWork(string $workKey): array
    {
        $normalized = str_starts_with($workKey, '/works/') ? $workKey : "/works/{$workKey}";
        $url = self::BASE_URL . $normalized . '.json';

        $payload = $this->getJsonWithRetries($url);

        return is_array($payload) ? $payload : [];
    }

    private function getJsonWithRetries(string $url): array
    {
        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            $json = $this->get($url);
            if ($json !== null) {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }
            if ($attempt < $this->retries) {
                usleep((int) min(0.75 * $attempt, 5.0) * 1_000_000);
            }
        }

        return [];
    }

    private function get(string $url): ?string
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ["User-Agent: " . self::USER_AGENT],
        ]);

        $body = curl_exec($handle);
        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '' || $statusCode >= 400) {
            return null;
        }

        return $body;
    }
}

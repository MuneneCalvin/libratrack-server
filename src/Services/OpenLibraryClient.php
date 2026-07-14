<?php

declare(strict_types=1);

namespace LibraTrack\Services;

final class OpenLibraryClient
{
    private const SEARCH_URL = 'https://openlibrary.org/search.json';
    private const BASE_URL = 'https://openlibrary.org';
    private const USER_AGENT = 'LibraTrack Open Library importer';
    private const SEARCH_FIELDS = 'key,title,author_name,isbn,publisher,first_publish_year,cover_i,subject,language,edition_count,ratings_average,ratings_count,want_to_read_count,currently_reading_count,already_read_count';

    /** @var null|callable(string, int, bool): array{body: ?string, statusCode: int, error: string} */
    private $request;

    private ?string $lastError = null;

    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $retries = 5,
        private readonly bool $verifySsl = true,
        ?callable $request = null
    ) {
        $this->request = $request;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
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
        $this->lastError = null;

        for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
            $json = $this->get($url);
            if ($json !== null) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $this->lastError = null;
                    return $decoded;
                }

                $this->lastError = 'Invalid JSON response from Open Library';
                return [];
            }
            if ($attempt < $this->retries) {
                usleep((int) (min(0.75 * $attempt, 5.0) * 1_000_000));
            }
        }

        return [];
    }

    private function get(string $url): ?string
    {
        if ($this->request !== null) {
            $response = ($this->request)($url, $this->timeoutSeconds, $this->verifySsl);
            return $this->bodyOrError($url, $response['body'], $response['statusCode'], $response['error']);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER => ["User-Agent: " . self::USER_AGENT],
        ]);

        $body = curl_exec($handle);
        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);

        return $this->bodyOrError($url, $body === false ? null : $body, (int) $statusCode, $error);
    }

    private function bodyOrError(string $url, ?string $body, int $statusCode, string $error): ?string
    {
        if ($body === null || $error !== '' || $statusCode >= 400) {
            $this->lastError = $error !== ''
                ? $error
                : "HTTP {$statusCode} from {$url}";
            return null;
        }

        return $body;
    }
}

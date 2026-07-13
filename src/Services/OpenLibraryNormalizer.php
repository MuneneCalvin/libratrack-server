<?php

declare(strict_types=1);

namespace LibraTrack\Services;

final class OpenLibraryNormalizer
{
    private const MAX_TITLE_LENGTH = 500;
    private const MAX_AUTHOR_LENGTH = 500;
    private const MAX_PUBLISHER_LENGTH = 255;
    private const MAX_SYNOPSIS_LENGTH = 3000;
    private const MAX_SUBJECT_LENGTH = 80;
    private const MAX_SUBJECTS = 12;
    private const MAX_LANGUAGES = 8;

    public static function chooseIsbn(array $isbns): ?string
    {
        $normalized = array_filter(array_map(
            static fn (mixed $value): string => strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $value)),
            $isbns
        ));

        foreach ($normalized as $candidate) {
            if (preg_match('/^\d{13}$/', $candidate) === 1) {
                return $candidate;
            }
        }
        foreach ($normalized as $candidate) {
            if (preg_match('/^\d{9}[0-9X]$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    public static function buildCoverUrl(?string $isbn, mixed $coverId): ?string
    {
        if ($isbn !== null && $isbn !== '') {
            return "https://covers.openlibrary.org/b/isbn/{$isbn}-L.jpg";
        }
        if (is_int($coverId) || (is_string($coverId) && ctype_digit($coverId))) {
            return "https://covers.openlibrary.org/b/id/{$coverId}-L.jpg";
        }

        return null;
    }

    public static function normalize(array $doc, string $categoryName): ?array
    {
        $title = self::firstText($doc['title'] ?? null, self::MAX_TITLE_LENGTH);
        if ($title === null) {
            return null;
        }

        $authors = array_map(
            static fn (mixed $name): string => self::truncate(trim((string) $name), self::MAX_AUTHOR_LENGTH),
            array_filter((array) ($doc['author_name'] ?? []))
        );
        $author = self::truncate(implode(', ', $authors), self::MAX_AUTHOR_LENGTH);
        if ($author === '') {
            return null;
        }

        $isbn = self::chooseIsbn((array) ($doc['isbn'] ?? []));
        if ($isbn === null) {
            return null;
        }

        $publisher = self::firstText($doc['publisher'] ?? null, self::MAX_PUBLISHER_LENGTH);
        $publishedYear = is_int($doc['first_publish_year'] ?? null) ? $doc['first_publish_year'] : null;

        return [
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'publisher' => $publisher,
            'publishedYear' => $publishedYear,
            'coverUrl' => self::buildCoverUrl($isbn, $doc['cover_i'] ?? null),
            'openLibraryWorkKey' => self::normalizeWorkKey($doc['key'] ?? null),
            'synopsis' => null,
            'subjects' => self::cleanUniqueList((array) ($doc['subject'] ?? []), self::MAX_SUBJECTS, self::MAX_SUBJECT_LENGTH),
            'languageCodes' => self::cleanUniqueList((array) ($doc['language'] ?? []), self::MAX_LANGUAGES, 12),
            'editionCount' => self::positiveInt($doc['edition_count'] ?? null),
            'ratingAverage' => self::positiveFloat($doc['ratings_average'] ?? null),
            'ratingCount' => self::positiveInt($doc['ratings_count'] ?? null),
            'wantToReadCount' => self::positiveInt($doc['want_to_read_count'] ?? null),
            'currentlyReadingCount' => self::positiveInt($doc['currently_reading_count'] ?? null),
            'alreadyReadCount' => self::positiveInt($doc['already_read_count'] ?? null),
            'categoryName' => $categoryName,
        ];
    }

    public static function enrichWithWorkDetails(array $candidate, array $work): array
    {
        $candidate['subjects'] = self::mergeUniqueLists(
            $candidate['subjects'] ?? [],
            (array) ($work['subjects'] ?? []),
            self::MAX_SUBJECTS,
            self::MAX_SUBJECT_LENGTH
        );
        $candidate['synopsis'] = self::extractSynopsis($work) ?? $candidate['synopsis'] ?? null;

        return $candidate;
    }

    private static function extractSynopsis(array $work): ?string
    {
        $description = $work['description'] ?? null;
        $text = is_array($description) ? ($description['value'] ?? null) : $description;
        if (is_string($text) && trim($text) !== '' && !self::looksLikePhysicalDescription($text)) {
            return self::truncate(trim($text), self::MAX_SYNOPSIS_LENGTH);
        }

        $firstSentence = $work['first_sentence'] ?? null;
        $sentenceText = is_array($firstSentence) ? ($firstSentence['value'] ?? null) : $firstSentence;
        if (is_string($sentenceText) && trim($sentenceText) !== '') {
            return self::truncate(trim($sentenceText), self::MAX_SYNOPSIS_LENGTH);
        }

        $excerpts = (array) ($work['excerpts'] ?? []);
        foreach ($excerpts as $excerpt) {
            $excerptText = is_array($excerpt) ? ($excerpt['excerpt'] ?? null) : null;
            if (is_string($excerptText) && trim($excerptText) !== '') {
                return self::truncate(trim($excerptText), self::MAX_SYNOPSIS_LENGTH);
            }
        }

        return null;
    }

    private static function looksLikePhysicalDescription(string $text): bool
    {
        return preg_match('/^\s*[ivxlc]*,?\s*\d+\s*(pages?|p\.)/i', $text) === 1
            || preg_match('/\d+\s*cm\b/i', $text) === 1;
    }

    private static function normalizeWorkKey(mixed $key): ?string
    {
        if (!is_string($key) || $key === '') {
            return null;
        }
        if (str_starts_with($key, '/works/')) {
            return $key;
        }
        if (preg_match('/^OL\d+W$/', $key) === 1) {
            return "/works/{$key}";
        }

        return null;
    }

    private static function firstText(mixed $value, int $maxLength): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : self::truncate($trimmed, $maxLength);
    }

    private static function truncate(string $value, int $maxLength): string
    {
        return mb_substr($value, 0, $maxLength);
    }

    private static function cleanUniqueList(array $values, int $maxItems, int $maxLength): array
    {
        $seen = [];
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = self::truncate(trim($value), $maxLength);
            $key = strtolower($trimmed);
            if ($trimmed === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $trimmed;
            if (count($result) >= $maxItems) {
                break;
            }
        }

        return $result;
    }

    private static function mergeUniqueLists(array $first, array $second, int $maxItems, int $maxLength): array
    {
        return self::cleanUniqueList([...$first, ...$second], $maxItems, $maxLength);
    }

    private static function positiveInt(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private static function positiveFloat(mixed $value): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float >= 0 ? $float : null;
    }
}

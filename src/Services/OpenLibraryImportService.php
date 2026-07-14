<?php

declare(strict_types=1);

namespace LibraTrack\Services;

use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\CategoryRepository;

final class OpenLibraryImportService
{
    private const TOPIC_QUERIES = [
        ['Fiction', 'fiction'],
        ['Classics', 'classic literature'],
        ['Science', 'science'],
        ['Technology', 'technology'],
        ['Programming', 'computer programming'],
        ['Business', 'business'],
        ['History', 'history'],
        ['Biography', 'biography'],
        ['Education', 'education'],
        ['Health', 'health'],
        ['Children', 'children books'],
        ['Literature', 'literature'],
    ];

    public function __construct(
        private readonly OpenLibraryClient $client,
        private readonly CategoryRepository $categories,
        private readonly BookRepository $books
    ) {
    }

    public function run(array $options): array
    {
        $limit = $options['limit'];
        $copies = $options['copies'];
        $pageSize = $options['pageSize'];
        $skipWorkDetails = $options['skipWorkDetails'];

        $imported = 0;
        $duplicates = 0;
        $invalid = 0;
        $categoriesCreated = 0;
        $detailFailures = 0;

        foreach (self::TOPIC_QUERIES as [$categoryName, $query]) {
            if ($imported >= $limit) {
                break;
            }

            $category = $this->categories->findByName($categoryName);
            if ($category === null) {
                $this->categories->create($categoryName);
                $categoriesCreated++;
            }

            $page = 1;
            while ($imported < $limit) {
                $docs = $this->client->searchDocs($query, $page, $pageSize);
                if ($docs === []) {
                    break;
                }

                foreach ($docs as $doc) {
                    if ($imported >= $limit) {
                        break;
                    }

                    $candidate = OpenLibraryNormalizer::normalize($doc, $categoryName);
                    if ($candidate === null) {
                        $invalid++;
                        continue;
                    }
                    if ($this->books->findByIsbn($candidate['isbn']) !== null) {
                        $duplicates++;
                        continue;
                    }

                    if (!$skipWorkDetails && $candidate['openLibraryWorkKey'] !== null) {
                        $work = $this->client->fetchWork($candidate['openLibraryWorkKey']);
                        if ($work === []) {
                            $detailFailures++;
                        } else {
                            $candidate = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);
                        }
                    }

                    $categoryRow = $this->categories->findByName($categoryName);
                    $candidate['categoryId'] = (int) $categoryRow['id'];
                    $candidate['totalCopies'] = $copies;
                    $candidate['availableCopies'] = $copies;

                    $this->books->create($candidate);
                    $imported++;
                }

                $page++;
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'categoriesCreated' => $categoriesCreated,
            'detailFailures' => $detailFailures,
        ];
    }
}

<?php

declare(strict_types=1);

use LibraTrack\Services\OpenLibraryNormalizer;
use PHPUnit\Framework\TestCase;

final class OpenLibraryNormalizerTest extends TestCase
{
    public function testChooseIsbnPrefersIsbn13OverIsbn10(): void
    {
        $isbn = OpenLibraryNormalizer::chooseIsbn(['0-13-235088-2', '978-0132350884']);

        $this->assertSame('9780132350884', $isbn);
    }

    public function testChooseIsbnFallsBackToIsbn10(): void
    {
        $isbn = OpenLibraryNormalizer::chooseIsbn(['0-13-235088-2']);

        $this->assertSame('0132350882', $isbn);
    }

    public function testChooseIsbnReturnsNullWhenNoneValid(): void
    {
        $this->assertNull(OpenLibraryNormalizer::chooseIsbn(['not-an-isbn']));
        $this->assertNull(OpenLibraryNormalizer::chooseIsbn([]));
    }

    public function testBuildCoverUrlPrefersIsbn(): void
    {
        $url = OpenLibraryNormalizer::buildCoverUrl('9780132350884', 12345);

        $this->assertSame('https://covers.openlibrary.org/b/isbn/9780132350884-L.jpg', $url);
    }

    public function testBuildCoverUrlFallsBackToCoverId(): void
    {
        $url = OpenLibraryNormalizer::buildCoverUrl(null, 12345);

        $this->assertSame('https://covers.openlibrary.org/b/id/12345-L.jpg', $url);
    }

    public function testBuildCoverUrlReturnsNullWhenNeitherAvailable(): void
    {
        $this->assertNull(OpenLibraryNormalizer::buildCoverUrl(null, null));
    }

    public function testNormalizeExtractsAllFields(): void
    {
        $doc = [
            'key' => '/works/OL17618370W',
            'title' => 'Clean Code',
            'author_name' => ['Robert C. Martin'],
            'isbn' => ['0132350882', '9780132350884'],
            'publisher' => ['Prentice Hall'],
            'first_publish_year' => 2008,
            'cover_i' => 12345,
            'subject' => ['Computer software', 'Agile software development'],
            'language' => ['eng', 'spa'],
            'edition_count' => 13,
            'ratings_average' => 4.46,
            'ratings_count' => 41,
            'want_to_read_count' => 823,
            'currently_reading_count' => 35,
            'already_read_count' => 61,
        ];

        $candidate = OpenLibraryNormalizer::normalize($doc, 'Technology');

        $this->assertNotNull($candidate);
        $this->assertSame('Clean Code', $candidate['title']);
        $this->assertSame('Robert C. Martin', $candidate['author']);
        $this->assertSame('9780132350884', $candidate['isbn']);
        $this->assertSame('Prentice Hall', $candidate['publisher']);
        $this->assertSame(2008, $candidate['publishedYear']);
        $this->assertSame('https://covers.openlibrary.org/b/isbn/9780132350884-L.jpg', $candidate['coverUrl']);
        $this->assertSame('/works/OL17618370W', $candidate['openLibraryWorkKey']);
        $this->assertSame(['Computer software', 'Agile software development'], $candidate['subjects']);
        $this->assertSame(['eng', 'spa'], $candidate['languageCodes']);
        $this->assertSame(13, $candidate['editionCount']);
        $this->assertSame(4.46, $candidate['ratingAverage']);
        $this->assertSame(41, $candidate['ratingCount']);
        $this->assertSame(823, $candidate['wantToReadCount']);
        $this->assertSame(35, $candidate['currentlyReadingCount']);
        $this->assertSame(61, $candidate['alreadyReadCount']);
        $this->assertSame('Technology', $candidate['categoryName']);
    }

    public function testNormalizeReturnsNullWhenTitleMissing(): void
    {
        $this->assertNull(OpenLibraryNormalizer::normalize(['author_name' => ['A'], 'isbn' => ['9780132350884']], 'Fiction'));
    }

    public function testNormalizeReturnsNullWhenAuthorMissing(): void
    {
        $this->assertNull(OpenLibraryNormalizer::normalize(['title' => 'T', 'isbn' => ['9780132350884']], 'Fiction'));
    }

    public function testNormalizeReturnsNullWhenIsbnMissing(): void
    {
        $this->assertNull(OpenLibraryNormalizer::normalize(['title' => 'T', 'author_name' => ['A']], 'Fiction'));
    }

    public function testEnrichMergesSubjectsWithCandidateFirst(): void
    {
        $candidate = ['subjects' => ['Computer software'], 'synopsis' => null];
        $work = ['subjects' => ['Software design', 'Computer software']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame(['Computer software', 'Software design'], $enriched['subjects']);
    }

    public function testEnrichUsesFirstSentenceWhenDescriptionMissing(): void
    {
        $candidate = ['subjects' => [], 'synopsis' => null];
        $work = ['first_sentence' => ['value' => 'A great opening line.']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame('A great opening line.', $enriched['synopsis']);
    }

    public function testEnrichIgnoresPhysicalDescriptionAndFallsBackToFirstSentence(): void
    {
        $candidate = ['subjects' => [], 'synopsis' => null];
        $work = ['description' => 'ix, 340 pages : 20 cm', 'first_sentence' => ['value' => 'Real synopsis.']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame('Real synopsis.', $enriched['synopsis']);
    }

    public function testEnrichUsesDescriptionValueWhenItIsNotPhysical(): void
    {
        $candidate = ['subjects' => [], 'synopsis' => null];
        $work = ['description' => ['value' => 'A book about writing clean code.']];

        $enriched = OpenLibraryNormalizer::enrichWithWorkDetails($candidate, $work);

        $this->assertSame('A book about writing clean code.', $enriched['synopsis']);
    }
}

<?php

declare(strict_types=1);

use LibraTrack\Services\OpenLibraryClient;
use PHPUnit\Framework\TestCase;

final class OpenLibraryClientTest extends TestCase
{
    public function testSearchDocsReturnsDecodedOpenLibraryDocs(): void
    {
        $client = new OpenLibraryClient(30, 1, true, function (): array {
            return [
                'body' => json_encode(['docs' => [['title' => 'Clean Code']]], JSON_THROW_ON_ERROR),
                'statusCode' => 200,
                'error' => '',
            ];
        });

        $docs = $client->searchDocs('fiction', 1, 5);

        $this->assertSame([['title' => 'Clean Code']], $docs);
        $this->assertNull($client->lastError());
    }

    public function testSearchDocsRecordsLastCurlErrorAfterRetries(): void
    {
        $attempts = 0;
        $client = new OpenLibraryClient(30, 2, true, function () use (&$attempts): array {
            $attempts++;
            return [
                'body' => null,
                'statusCode' => 0,
                'error' => 'SSL certificate problem: unable to get local issuer certificate',
            ];
        });

        $docs = $client->searchDocs('fiction', 1, 5);

        $this->assertSame([], $docs);
        $this->assertSame(2, $attempts);
        $this->assertStringContainsString('SSL certificate problem', $client->lastError());
    }

    public function testInjectedRequestReceivesSslVerificationPreference(): void
    {
        $verifySslValue = null;
        $client = new OpenLibraryClient(30, 1, false, function (string $url, int $timeout, bool $verifySsl) use (&$verifySslValue): array {
            $verifySslValue = $verifySsl;
            return [
                'body' => json_encode(['docs' => []], JSON_THROW_ON_ERROR),
                'statusCode' => 200,
                'error' => '',
            ];
        });

        $client->searchDocs('fiction', 1, 5);

        $this->assertFalse($verifySslValue);
    }
}

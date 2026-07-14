<?php

declare(strict_types=1);

use LibraTrack\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testSuccessEnvelope(): void
    {
        $response = Response::success(['id' => 1]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['status' => 'success', 'data' => ['id' => 1]], $response->payload);
    }

    public function testPaginatedEnvelope(): void
    {
        $response = Response::paginated([['id' => 1]], [
            'total' => 1,
            'page' => 1,
            'limit' => 20,
            'totalPages' => 1,
        ]);

        $this->assertSame('success', $response->payload['status']);
        $this->assertSame([['id' => 1]], $response->payload['data']);
        $this->assertSame(1, $response->payload['meta']['total']);
    }

    public function testErrorEnvelopeWithExtraFields(): void
    {
        $response = Response::error('Limit reached', 400, ['remainingSlots' => 0]);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame([
            'status' => 'error',
            'message' => 'Limit reached',
            'remainingSlots' => 0,
        ], $response->payload);
    }
}

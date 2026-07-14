<?php

declare(strict_types=1);

use LibraTrack\Core\Pagination;
use LibraTrack\Core\Request;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testDefaultsToPageOneLimitTen(): void
    {
        $request = new Request('GET', '/api/books/', [], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(1, $pagination->page);
        $this->assertSame(10, $pagination->limit);
        $this->assertSame(0, $pagination->offset);
    }

    public function testReadsPageAndLimitFromQuery(): void
    {
        $request = new Request('GET', '/api/books/', ['page' => '3', 'limit' => '20'], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(3, $pagination->page);
        $this->assertSame(20, $pagination->limit);
        $this->assertSame(40, $pagination->offset);
    }

    public function testClampsLimitToMaximumOfOneHundred(): void
    {
        $request = new Request('GET', '/api/books/', ['limit' => '500'], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(100, $pagination->limit);
    }

    public function testClampsPageToMinimumOfOne(): void
    {
        $request = new Request('GET', '/api/books/', ['page' => '0'], [], [], null);
        $pagination = Pagination::fromRequest($request);

        $this->assertSame(1, $pagination->page);
    }

    public function testMetaComputesTotalPages(): void
    {
        $pagination = Pagination::fromRequest(new Request('GET', '/api/books/', ['page' => '2', 'limit' => '20'], [], [], null));

        $meta = $pagination->meta(42);

        $this->assertSame(['total' => 42, 'page' => 2, 'limit' => 20, 'totalPages' => 3], $meta);
    }

    public function testMetaTotalPagesIsZeroWhenTotalIsZero(): void
    {
        $pagination = Pagination::fromRequest(new Request('GET', '/api/books/', [], [], [], null));

        $meta = $pagination->meta(0);

        $this->assertSame(0, $meta['totalPages']);
    }
}

<?php

declare(strict_types=1);

namespace LibraTrack\Core;

final class Pagination
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

    public function __construct(
        public readonly int $page,
        public readonly int $limit,
        public readonly int $offset
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $limit = (int) ($request->query['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit === 0 ? self::DEFAULT_LIMIT : $limit));

        return new self($page, $limit, ($page - 1) * $limit);
    }

    public function meta(int $total): array
    {
        return [
            'total' => $total,
            'page' => $this->page,
            'limit' => $this->limit,
            'totalPages' => $total === 0 ? 0 : (int) ceil($total / $this->limit),
        ];
    }
}

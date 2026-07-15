<?php

declare(strict_types=1);

namespace LibraTrack\Services;

use DateInterval;
use DateTimeImmutable;
use LibraTrack\Core\ValidationException;
use LibraTrack\Repositories\BookRepository;
use LibraTrack\Repositories\MemberRepository;
use LibraTrack\Repositories\SettingsRepository;
use LibraTrack\Repositories\TransactionRepository;

final class BorrowingService
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly MemberRepository $members,
        private readonly BookRepository $books,
        private readonly SettingsRepository $settings
    ) {
    }

    /**
     * @param array<int> $bookIds
     */
    public function issue(int $memberId, array $bookIds): int
    {
        if ($this->members->find($memberId) === null) {
            throw new ValidationException('Member not found', 404);
        }

        $settings = $this->settings->all();
        $maxBooks = $settings['maxBooksPerMember'];
        $activeCount = $this->members->countActiveBorrows($memberId);
        $bookIds = array_values(array_map('intval', $bookIds));

        if ($activeCount + count($bookIds) > $maxBooks) {
            $remainingSlots = max($maxBooks - $activeCount, 0);
            throw new ValidationException(
                "Member cannot borrow more than {$maxBooks} books at once",
                400,
                ['activeBorrowCount' => $activeCount, 'maxBooks' => $maxBooks, 'remainingSlots' => $remainingSlots]
            );
        }

        foreach ($bookIds as $bookId) {
            $book = $this->books->find($bookId);
            if ($book === null) {
                throw new ValidationException('Book not found', 404);
            }
            if ((int) $book['available_copies'] < 1) {
                throw new ValidationException("Book \"{$book['title']}\" is not available");
            }
        }

        $dueDate = (new DateTimeImmutable())->add(new DateInterval("P{$settings['borrowDays']}D"));

        return $this->transactions->create($memberId, $bookIds, $dueDate);
    }
}

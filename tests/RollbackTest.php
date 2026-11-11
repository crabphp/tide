<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Connection;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A model written in a transaction that is rolled back is again what the database holds.
 */
final class RollbackTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testANewModelIsNewAgainAndRunningTheTransactionAgainInsertsIt(string $driver): void
    {
        $this->start($driver);
        $book = new Book('Tried twice');
        $attempts = 0;
        // The shape of a deadlock retry: the same callback, run until it commits.
        while (true) {
            try {
                $this->db->transaction(static function () use ($book, &$attempts): void {
                    $book->save();
                    if (++$attempts === 1) {
                        throw new \RuntimeException('deadlock');
                    }
                });
                break;
            } catch (\RuntimeException) {
                $afterFailure = [$book->stored(), isset($book->id)];
            }
        }
        self::assertSame([false, false], $afterFailure ?? null);
        self::assertSame(2, $attempts);
        self::assertTrue($book->stored());
        self::assertSame('Tried twice', Book::find($book->id)?->title);
        self::assertSame(1, Book::query()->where('title', 'Tried twice')->count());
    }

    #[DataProvider('drivers')]
    public function testAChangeAndADeletionAreForgottenWithTheirTransaction(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        $book->title = 'Changed';
        try {
            $this->db->transaction(static function () use ($book): never {
                $book->save();
                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException) {
            // Checked below.
        }
        self::assertSame('Alpha', $this->db->table('tide_books')->where('id', 1)->value('title'));
        // The model still holds the change, and knows it is not written.
        $book->save();
        self::assertSame('Changed', $this->db->table('tide_books')->where('id', 1)->value('title'));

        try {
            $this->db->transaction(static function () use ($book): never {
                $book->delete();
                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException) {
            // Checked below.
        }
        self::assertTrue($book->stored());
        self::assertNotNull(Book::find(1));
        $book->title = 'Still here';
        $book->save();
        self::assertSame('Still here', $this->db->table('tide_books')->where('id', 1)->value('title'));
    }

    #[DataProvider('drivers')]
    public function testAnInnerRollbackForgetsOnlyTheInnerWriteAndACommitForgetsNothing(string $driver): void
    {
        $this->start($driver);
        $kept = new Book('Kept');
        $undone = new Book('Undone');
        $this->db->transaction(static function (Connection $tx) use ($kept, $undone): void {
            $kept->save();
            try {
                $tx->transaction(static function () use ($undone): never {
                    $undone->save();
                    throw new \RuntimeException('inner');
                });
            } catch (\RuntimeException) {
                // Only the inner transaction is undone.
            }
        });
        self::assertTrue($kept->stored());
        self::assertFalse($undone->stored());
        self::assertSame(1, Book::query()->where('title', 'Kept')->count());
        self::assertSame(0, Book::query()->where('title', 'Undone')->count());

        // Committed, a model stays stored when a later transaction on the same connection fails.
        try {
            $this->db->transaction(static function (): never {
                throw new \RuntimeException('unrelated');
            });
        } catch (\RuntimeException) {
            // Checked below.
        }
        self::assertTrue($kept->stored());
        $kept->title = 'Kept and changed';
        $kept->save();
        self::assertSame(1, Book::query()->where('title', 'Kept and changed')->count());
    }
}

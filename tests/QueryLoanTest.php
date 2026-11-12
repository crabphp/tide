<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Connection;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Tide\ModelQuery;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\RecordingDatabase;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

final readonly class LoanTitle
{
    public function __construct(
        public int $id,
        public string $title,
    ) {}
}

final class QueryLoanTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testEveryTerminalRefusesAnEndedLoan(string $driver): void
    {
        $this->start($driver);
        $query = $this->db->run(static fn(): ModelQuery => Book::query()->with('author')->where('id', 1));
        foreach ([
            static fn(ModelQuery $q) => $q->get(),
            static fn(ModelQuery $q) => $q->first(),
            static fn(ModelQuery $q) => $q->find(1),
            static fn(ModelQuery $q) => $q->exists(),
            static fn(ModelQuery $q) => $q->count(),
            static fn(ModelQuery $q) => $q->sum('votes'),
            static fn(ModelQuery $q) => $q->avg('votes'),
            static fn(ModelQuery $q) => $q->min('votes'),
            static fn(ModelQuery $q) => $q->max('votes'),
            static fn(ModelQuery $q) => $q->pluck('id'),
            static fn(ModelQuery $q) => $q->value('id'),
            static fn(ModelQuery $q) => $q->paginate(),
            static fn(ModelQuery $q) => $q->simplePaginate(),
            static fn(ModelQuery $q) => $q->cursorPaginate(),
            static fn(ModelQuery $q) => $q->chunkById(2, static fn(): bool => false),
            static fn(ModelQuery $q) => $q->update(['title' => 'escaped']),
            static fn(ModelQuery $q) => $q->increment('votes'),
            static fn(ModelQuery $q) => $q->decrement('votes'),
            static fn(ModelQuery $q) => $q->delete(),
            static fn(ModelQuery $q) => $q->loadRelations([Book::find(1)]),
        ] as $run) {
            $error = null;
            try {
                $run(clone $query);
            } catch (\LogicException $caught) {
                $error = $caught;
            }
            self::assertInstanceOf(\LogicException::class, $error);
            self::assertStringContainsString('start the query where it runs', $error->getMessage());
            self::assertStringContainsString(Book::class, $error->getMessage());
        }
        self::assertSame('Alpha', Book::find(1)->title);
    }

    #[DataProvider('drivers')]
    public function testAnEndedLoanIsNotRevivedByALaterLoanOrByInto(string $driver): void
    {
        $this->start($driver);
        $query = $this->db->run(static fn(): ModelQuery => Book::query()->where('id', 1));
        $records = $this->db->run(static fn() => Book::query()->where('id', 1)->into(LoanTitle::class));
        foreach ([
            'a later loan of the same connection' => fn() => $this->db->run(static fn() => $query->first()),
            'a later transaction' => fn() => $this->db->transaction(static fn() => $query->get()),
            'records after into(), outside any loan' => static fn() => $records->get(),
            'records after into(), in a later loan' => fn() => $this->db->run(static fn() => $records->first()),
        ] as $case => $run) {
            try {
                $run();
                self::fail("An ended loan ran: {$case}.");
            } catch (\LogicException $error) {
                self::assertMatchesRegularExpression(
                    '/start the query where it runs|that loan has ended/',
                    $error->getMessage(),
                    $case,
                );
            }
        }
        // Within its loan, into() runs as any query does.
        self::assertSame(
            'Alpha',
            $this->db->run(static fn() => Book::query()->where('id', 1)->into(LoanTitle::class)->first()->title),
        );
    }

    #[DataProvider('drivers')]
    public function testNestedLoansRefuseTheOuterQueryAndRestoreItAfterwards(string $driver): void
    {
        $this->start($driver);
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        self::assertSame($recording, Book::query()->connection);
        $seen = [];
        $this->db->run(function () use (&$seen): void {
            $outer = Book::query();
            $this->db->run(static function () use ($outer, &$seen): void {
                try {
                    $outer->count();
                } catch (\LogicException $error) {
                    $seen[] = $error->getMessage();
                }
                $seen[] = Book::query()->count();
            });
            $seen[] = $outer->count();
        });
        self::assertCount(3, $seen);
        self::assertStringContainsString('innermost', $seen[0]);
        self::assertSame([4, 4], array_slice($seen, 1));
    }

    #[DataProvider('drivers')]
    public function testAnExpiredQueryCannotWriteInAnotherFibersTransaction(string $driver): void
    {
        $this->start($driver);
        $query = $this->db->run(static fn(): ModelQuery => Book::query()->where('id', 1));
        $loop = EventLoop::current();
        $ready = $loop->getSuspension();
        $release = null;
        $loan = null;
        $timer = $loop->delay(5, static function () use ($ready, &$release): void {
            ($release ?? $ready)->throw(new \RuntimeException('Timed out at the competing transaction barrier.'));
        });
        $task = Task::spawn($loop, function () use ($loop, $ready, &$release, &$loan): void {
            $this->db->transaction(static function (Connection $tx) use ($loop, $ready, &$release, &$loan): never {
                $loan = $tx;
                $release = $loop->getSuspension();
                $ready->resume();
                $release->suspend();
                throw new \RuntimeException('rollback competitor');
            });
        });
        $error = $rollback = null;
        try {
            $ready->suspend();
            try {
                $query->update(['title' => 'escaped']);
            } catch (\LogicException $caught) {
                $error = $caught;
            }
        } finally {
            $release?->resume();
            try {
                $task->await();
            } catch (\RuntimeException $caught) {
                $rollback = $caught->getMessage();
            }
            $loop->cancel($timer);
        }
        self::assertSame($query->connection, $loan, 'The expired connection was reused by the competitor.');
        self::assertInstanceOf(\LogicException::class, $error);
        self::assertSame('rollback competitor', $rollback);
        self::assertSame('Alpha', Book::find(1)->title);
    }

    #[DataProvider('drivers')]
    public function testAQueryKeepsItsOriginalDatabaseAndChecksEagerLoans(string $driver): void
    {
        $this->start($driver);
        $database = $this->db;
        $query = Book::query();
        Tide::use($this->database('sqlite'));
        self::assertSame('Ada', $query->with('author')->find(1)->author->name);
        Tide::use($database);
        $escaped = null;
        $database->run(static function () use (&$escaped): void {
            Book::query()->with('author', static function (ModelQuery $q) use (&$escaped): void {
                $escaped = $q;
            })->get();
        });
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('start the query where it runs');
        $escaped->get();
    }
}

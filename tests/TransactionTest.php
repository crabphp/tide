<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Connection;
use Crab\Database\Database;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Tide\Model;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

final class TransactionTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testModelStatementsAndQueriesCreatedInsideShareRollback(string $driver): void
    {
        $this->start($driver);
        $book = new Book('Rolled back');
        $inside = null;
        $before = Book::query();
        try {
            $this->db->transaction(static function () use ($book, &$inside, $before): never {
                $query = Book::query();
                $book->save();
                $inside = [$query->count(), $before->count(), $query->find($book->id)->title];
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // The object keeps its snapshot; the database rolls the row back.
        }
        self::assertSame([5, 4, 'Rolled back'], $inside);
        self::assertSame(4, Book::query()->count());
        self::assertTrue($book->stored());
        self::assertNull(Book::find($book->id));
    }

    #[DataProvider('drivers')]
    public function testSavepointRollbackAndRunUseTheLentConnection(string $driver): void
    {
        $this->start($driver);
        $this->db->run(static function (Connection $loan): void {
            $loan->transaction(static function (Connection $tx): void {
                new Book('Outer')->save();
                try {
                    $tx->transaction(static function (): never {
                        new Book('Inner')->save();
                        throw new \RuntimeException('rollback inner');
                    });
                } catch (\RuntimeException) {
                    // Only the inner insert is undone.
                }
            });
        });
        self::assertSame(1, Book::query()->where('title', 'Outer')->count());
        self::assertSame(0, Book::query()->where('title', 'Inner')->count());
    }

    #[DataProvider('drivers')]
    public function testNestedDatabaseLoansRestoreTheOuterModelConnection(string $driver): void
    {
        $db = $this->fiberDatabase($driver);
        Tide::use($db);
        $seen = [];
        $db->transaction(static function (Connection $outer) use ($db, &$seen): void {
            $seen[] = FiberRow::query()->connection === $outer;
            $db->transaction(static function (Connection $inner) use ($outer, &$seen): void {
                $seen[] = FiberRow::query()->connection === $inner && $inner !== $outer;
                $row = new FiberRow();
                $row->id = 1;
                $row->name = 'Inner';
                $row->save();
            });
            $seen[] = FiberRow::query()->connection === $outer;
        });
        self::assertSame([true, true, true], $seen);
        $db->close();
    }

    #[DataProvider('drivers')]
    public function testOverlappingFibersSaveAndReadOnlyTheirOwnTransaction(string $driver): void
    {
        $db = $this->fiberDatabase($driver);
        Tide::use($db);
        $loop = EventLoop::current();
        $waiting = [];
        $seen = [];
        $timer = $loop->delay(5.0, static function () use (&$waiting): void {
            foreach ($waiting as $suspension) {
                $suspension->throw(new \RuntimeException('Timed out waiting for both model transactions.'));
            }
        });
        $tasks = [];
        for ($id = 1; $id <= 2; ++$id) {
            $tasks[] = Task::spawn($loop, static function () use ($db, $loop, $id, &$waiting, &$seen): void {
                $db->transaction(static function (Connection $tx) use ($loop, $id, &$waiting, &$seen): void {
                    $row = new FiberRow();
                    $row->id = $id;
                    $row->name = 'Fiber ' . $id;
                    $row->save();
                    $query = FiberRow::query();
                    $waiting[$id] = $loop->getSuspension();
                    if (count($waiting) === 2) {
                        $loop->defer(static function () use (&$waiting): void {
                            foreach ($waiting as $suspension) {
                                $suspension->resume();
                            }
                        });
                    }
                    $waiting[$id]->suspend();
                    unset($waiting[$id]);
                    $seen[$id] = [$query->connection === $tx, $query->find($id)->name, $query->find(3 - $id)];
                    $waiting[$id] = $loop->getSuspension();
                    if (count($seen) === 2) {
                        $loop->defer(static function () use (&$waiting): void {
                            foreach ($waiting as $suspension) {
                                $suspension->resume();
                            }
                        });
                    }
                    $waiting[$id]->suspend();
                    unset($waiting[$id]);
                });
            });
        }
        try {
            foreach ($tasks as $task) {
                $task->await();
            }
        } finally {
            $loop->cancel($timer);
            $db->close();
        }
        self::assertSame([true, 'Fiber 1', null], $seen[1]);
        self::assertSame([true, 'Fiber 2', null], $seen[2]);
    }

    private function fiberDatabase(string $driver): Database
    {
        if ($driver === 'sqlite') {
            // SQLite allows only one writer per file. Independent in-memory
            // sessions exercise overlapping loans without a blocking write lock.
            return new Database(static function (): \PDO {
                $pdo = new \PDO('sqlite::memory:');
                $pdo->exec('create table tide_fiber (id integer primary key, name text not null)');
                return $pdo;
            });
        }
        $db = $this->database($driver);
        $db->execute('drop table if exists tide_fiber');
        $db->execute('create table tide_fiber (id integer primary key, name varchar(100) not null)');
        return $db;
    }
}

#[Table('tide_fiber')]
final class FiberRow extends Model
{
    public int $id;
    public string $name;
}

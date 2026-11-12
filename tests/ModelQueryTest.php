<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Query;
use Crab\Tide\ModelMap;
use Crab\Tide\ModelQuery;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Status;
use PHPUnit\Framework\Attributes\DataProvider;

final class ModelQueryTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testWholeRowsFirstFindAndGetLeaveTheQueryUnchanged(string $driver): void
    {
        $this->start($driver);
        $query = Book::query()->where('votes', '>', 0)->orderBy('id');
        $sql = $query->toSql();
        self::assertStringContainsString('`tide_books`.`title`', $sql);
        self::assertSame('Alpha', $query->first()->title);
        self::assertSame('Beta', $query->find(2)->title);
        self::assertNull($query->find(100));
        self::assertCount(4, $query->get());
        self::assertSame($sql, $query->toSql());
        self::assertSame([0], $query->bindings());
        self::assertTrue($query->first()->stored());
        self::assertNotSame($query->first(), $query->first());
    }

    #[DataProvider('drivers')]
    public function testAggregatesAndPlainColumnReads(string $driver): void
    {
        $this->start($driver);
        $query = Book::query();
        self::assertTrue($query->exists());
        self::assertSame(4, $query->count());
        self::assertSame(10, $query->sum('votes'));
        self::assertSame(2.5, $query->avg('votes'));
        self::assertSame(1, $query->min('votes'));
        self::assertSame(4, $query->max('votes'));
        self::assertSame('Alpha', $query->orderBy('id')->value('title'));
        self::assertSame([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma', 4 => 'Delta'], $query->pluck('title', 'id'));
    }

    #[DataProvider('drivers')]
    public function testEveryPageAndChunkLoadsItsRelations(string $driver): void
    {
        $this->start($driver);
        $query = Book::query()->with('author')->orderBy('id');
        $page = $query->paginate(2);
        self::assertSame(4, $page->total);
        self::assertSame('Ada', $page->items[0]->author->name);
        self::assertTrue($page->hasMore);
        $simple = $query->simplePaginate(2, 2);
        self::assertNull($simple->total);
        self::assertSame(3, $simple->items[0]->id);
        self::assertSame('Grace', $simple->items[0]->author->name);
        self::assertFalse($simple->hasMore);
        $cursor = $query->cursorPaginate(2);
        self::assertSame('Ada', $cursor->items[0]->author->name);
        $last = $query->cursorPaginate(2, $cursor->nextCursor);
        self::assertSame(3, $last->items[0]->id);
        self::assertNull($last->nextCursor);
        $chunks = [];
        $query = Book::query()->with('author');
        $query->chunkById(2, static function (array $models) use (&$chunks): void {
            $chunks[] = array_map(static fn(Book $book): array => [$book->id, $book->author?->name], $models);
        });
        self::assertSame([[[1, 'Ada'], [2, 'Ada']], [[3, 'Grace'], [4, null]]], $chunks);
        $calls = 0;
        $query->chunkById(1, static function () use (&$calls): bool {
            ++$calls;
            return false;
        });
        self::assertSame(1, $calls);
    }

    #[DataProvider('drivers')]
    public function testBulkWritesConvertFieldsAndDoNotMutateHeldModels(string $driver): void
    {
        $this->start($driver);
        $held = Book::find(1);
        $query = Book::query()->where('id', 1);
        $sql = $query->toSql();
        self::assertSame(1, $query->update([
            'status' => Status::Published,
            'meta' => ['a' => 1],
            'published_at' => new \DateTimeImmutable('2026-01-02 03:04:05'),
        ]));
        self::assertSame(Status::Draft, $held->status);
        self::assertSame(Status::Published, $query->first()->status);
        self::assertSame(['a' => 1], $query->first()->meta);
        self::assertSame(1, $query->increment('votes', 2));
        self::assertSame(1, $query->decrement('votes'));
        self::assertSame(2, $query->first()->votes);
        self::assertSame($sql, $query->toSql());
        self::assertSame(1, $query->delete());
        self::assertNull($query->first());
    }

    #[DataProvider('drivers')]
    public function testConditionsAcceptEnumsAndDatesAndWhenGetsTheModelQuery(string $driver): void
    {
        $this->start($driver);
        $date = new \DateTimeImmutable('2026-01-02 03:04:05');
        Book::query()->where('id', 1)->update(['published_at' => $date]);
        $seen = null;
        $query = Book::query()
            ->when(true, static function (ModelQuery $query) use (&$seen): void {
                $seen = $query;
                $query->where('status', Status::Draft);
            })
            ->when(
                false,
                static fn() => throw new \LogicException('not called'),
                static fn(ModelQuery $query) => $query->where('published_at', $date),
            );
        self::assertSame($query, $seen);
        self::assertSame([1], array_column($query->get(), 'id'));
        self::assertSame(4, Book::query()->whereIn('status', [Status::Draft, Status::Published])->count());
    }

    #[DataProvider('drivers')]
    public function testClonesBranchAndTapReceivesTheBuilder(string $driver): void
    {
        $this->start($driver);
        $query = Book::query()->where('votes', '>', 0);
        $branch = clone $query;
        $branch->where('id', 1)->tap(static fn(Query $query) => $query->whereRaw('votes < ?', [2]));
        self::assertSame(1, $branch->count());
        self::assertSame(4, $query->count());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('had the columns it reads changed in tap()');
        $query->tap(static fn(Query $query) => $query->select('id'))->first();
    }

    #[DataProvider('forwarded')]
    public function testEachForwardedMethodMatchesTheBuilder(string $method, array $arguments): void
    {
        $this->start('sqlite');
        $map = ModelMap::of(Book::class);
        $query = new ModelQuery($map, $this->db);
        $raw = $this->db
            ->table($map->table)
            ->select(...array_map(
                static fn(string $column): string => $map->table . '.' . $column,
                array_keys($map->columns),
            ));
        self::assertSame($query, $query->{$method}(...$arguments));
        // The model query names its own columns with its table, so a join after into() leaves none ambiguous.
        $qualify = static fn(mixed $column): mixed => is_string($column) && isset($map->columns[$column])
            ? $map->table . '.' . $column
            : $column;
        if (is_string($arguments[0] ?? null)) {
            $arguments[0] = $qualify($arguments[0]);
        } elseif (is_array($arguments[0] ?? null) && !array_is_list($arguments[0])) {
            $arguments[0] = array_combine(array_map($qualify, array_keys($arguments[0])), $arguments[0]);
        }
        if (in_array($method, ['whereColumn', 'orWhereColumn'], true)) {
            $other = isset($arguments[2]) ? 2 : 1;
            $arguments[$other] = $qualify($arguments[$other]);
        }
        $raw->{$method}(...$arguments);
        self::assertSame($raw->toSql(), $query->toSql());
        self::assertSame($raw->bindings(), $query->bindings());
    }

    public static function forwarded(): array
    {
        $cases = [];
        foreach (['where', 'orWhere'] as $method) {
            $cases[$method] = [$method, ['id', '>', 1]];
            $cases[$method . '-two'] = [$method, ['id', 1]];
            $cases[$method . '-array'] = [$method, [['id' => 1]]];
        }
        foreach (['whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn'] as $method) {
            $cases[$method] = [$method, ['id', [1, 2]]];
        }
        foreach (['whereNull', 'orWhereNull', 'whereNotNull', 'orWhereNotNull'] as $method) {
            $cases[$method] = [$method, ['author_id']];
        }
        foreach (['whereBetween', 'orWhereBetween', 'whereNotBetween', 'orWhereNotBetween'] as $method) {
            $cases[$method] = [$method, ['id', 1, 3]];
        }
        foreach (['whereColumn', 'orWhereColumn'] as $method) {
            $cases[$method] = [$method, ['id', '>', 'votes']];
        }
        foreach (['orderBy', 'orderByDesc', 'latest', 'oldest'] as $method) {
            $cases[$method] = [$method, ['id']];
        }
        foreach (['limit', 'offset', 'forPage'] as $method) {
            $cases[$method] = [$method, [2]];
        }
        $cases['lockForUpdate'] = ['lockForUpdate', []];
        return $cases;
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Cast;
use Crab\Contracts\Database\Query;
use Crab\Database\ColumnName;
use Crab\Database\Json;
use Crab\Tide\BelongsTo;
use Crab\Tide\HasMany;
use Crab\Tide\HasOne;
use Crab\Tide\Hidden;
use Crab\Tide\Model;
use Crab\Tide\ModelQuery;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\Category;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Money;
use Crab\Tide\Tests\Support\Price;
use Crab\Tide\Tests\Support\RecordingDatabase;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReviewFixesTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testJsonReadsThePublicStoredValuesColumnAndSkipsHiddenHooks(string $driver): void
    {
        $this->start($driver);
        $model = SnapshotColumn::find(1);
        self::assertSame(['id' => 1, 'storedValues' => []], $model->jsonSerialize());
        self::assertSame('{"id":1,"storedValues":[]}', json_encode($model, JSON_THROW_ON_ERROR));
        self::assertSame(['id' => 1], HiddenGetter::find(1)->jsonSerialize());
    }

    #[DataProvider('drivers')]
    public function testPersistenceAndRelationKeysBypassGetters(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_categories')->insert(['id' => 101, 'parent_id' => null, 'name' => 'victim']);
        $row = RawNode::query()->with('children')->find(1);
        self::assertSame(101, $row->id);
        self::assertSame(102, $row->children[0]->id);
        self::assertSame([2], $row->related('children')->pluck('id'));
        $child = RawNode::query()->with('parent')->find(2);
        self::assertSame(101, $child->parent->id);
        self::assertSame([1], $child->related('parent')->pluck('id'));
        $row->name = 'changed';
        $row->save();
        self::assertSame('changed', $this->db->table('tide_categories')->where('id', 1)->value('name'));
        self::assertSame('victim', $this->db->table('tide_categories')->where('id', 101)->value('name'));
        $row->refresh();
        self::assertSame('CHANGED', $row->name);
        self::assertSame('CHANGED', $row->jsonSerialize()['name']);
        $row->delete();
        self::assertNull(Category::find(1));
        self::assertNotNull(Category::find(101));
        $new = new RawNode();
        $new->parent_id = null;
        $new->name = 'inserted';
        $new->save();
        self::assertSame('inserted', $this->db->table('tide_categories')->where('id', 102)->value('name'));
        $new->save();
        self::assertSame('inserted', $this->db->table('tide_categories')->where('id', 102)->value('name'));
    }

    #[DataProvider('drivers')]
    public function testAsymmetricColumnsHydrateInsertAndRefresh(string $driver): void
    {
        $this->start($driver);
        $row = PrivateAuthor::find(1);
        self::assertSame([1, 'Ada'], [$row->id, $row->name]);
        $new = new PrivateAuthor('new');
        $new->save();
        self::assertTrue($new->stored());
        self::assertSame(3, $new->id);
        $this->db->table('tide_authors')->where('id', 3)->update(['name' => 'fresh']);
        $new->refresh();
        self::assertSame('fresh', $new->name);
        self::assertSame(3, $this->db->table('tide_authors')->count());
    }

    #[DataProvider('drivers')]
    public function testHookedAndAsymmetricRelationsAreRefusedBeforeSql(string $driver): void
    {
        $this->start($driver);
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        foreach ([
            HookedRelation::class,
            PrivateRelation::class,
            ProtectedRelation::class,
            VirtualRelation::class,
        ] as $class) {
            $error = null;
            try {
                $class::find(1);
            } catch (\LogicException $caught) {
                $error = $caught;
            }
            self::assertInstanceOf(\LogicException::class, $error);
            self::assertStringContainsString('::$parent', $error->getMessage());
            self::assertStringContainsString('plain public', $error->getMessage());
        }
        self::assertSame([], $recording->recording->statements);
    }

    #[DataProvider('drivers')]
    public function testVirtualGettersAreNeverReadByPersistenceJsonOrRelations(string $driver): void
    {
        $this->start($driver);
        $row = UnsafeVirtual::find(1);
        $row->name = 'changed';
        $row->save();
        self::assertSame(['id' => 1, 'parent_id' => null, 'name' => 'changed'], $row->jsonSerialize());
        UnsafeVirtual::load([$row], 'children');
        self::assertSame([2], array_column($row->children, 'id'));
        self::assertSame([2], $row->related('children')->pluck('id'));
        $row->refresh();
        self::assertSame('changed', $row->name);
    }

    #[DataProvider('drivers')]
    public function testMutableDatesAreSnapshottedForInterfaceAndMixedColumns(string $driver): void
    {
        $this->start($driver);
        foreach ([InterfaceDate::class, MixedDate::class] as $class) {
            $row = $class::find(1);
            $row->published_at = new \DateTime('2026-01-01');
            $row->save();
            $row->published_at->modify('+1 day');
            $row->save();
            self::assertSame(
                '2026-01-02 00:00:00',
                $this->db->table('tide_books')->where('id', 1)->value('published_at'),
            );
        }
    }

    #[DataProvider('drivers')]
    public function testRelatedOrConditionsRemainInsideTheParentForReadsAndWrites(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_categories')->insert(['id' => 4, 'parent_id' => 1, 'name' => 'leaf']);
        $query = Category::find(1)->related('children')->where('name', 'child')->orWhere('name', 'leaf');
        self::assertSame([2, 4], array_column((clone $query)->orderBy('id')->get(), 'id'));
        self::assertSame(2, $query->update(['name' => 'matched']));
        self::assertSame('leaf', Category::find(3)->name);
        $base = Category::find(1)->related('children')->where('id', 2);
        foreach ([
            static fn(ModelQuery $q) => $q->orWhereIn('id', [3, 4]),
            static fn(ModelQuery $q) => $q->orWhereNotIn('id', [1, 2]),
            static fn(ModelQuery $q) => $q->orWhereNull('parent_id'),
            static fn(ModelQuery $q) => $q->orWhereNotNull('parent_id'),
            static fn(ModelQuery $q) => $q->orWhereBetween('id', 3, 4),
            static fn(ModelQuery $q) => $q->orWhereNotBetween('id', 1, 2),
            static fn(ModelQuery $q) => $q->orWhereColumn('id', '=', 'id'),
            static fn(ModelQuery $q) => $q->orWhere(
                static fn(ModelQuery $group) => $group->where('id', 3)->orWhere('id', 4),
            ),
        ] as $condition) {
            $branch = clone $base;
            $condition($branch);
            self::assertSame([], array_diff($branch->pluck('id'), [2, 4]));
        }
        self::assertSame([2], $base->pluck('id'));
        self::assertSame(
            [4],
            array_column(
                Category::find(1)
                    ->related('children')
                    ->orderByDesc('id')
                    ->limit(1)
                    ->with('parent')
                    ->get(),
                'id',
            ),
        );
    }

    #[DataProvider('drivers')]
    public function testBulkWritesRefuseEveryUndeclaredColumnBeforeSql(string $driver): void
    {
        $this->start($driver);
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        foreach ([
            static fn() => MinimalBook::query()->update(['secret' => 'changed']),
            static fn() => MinimalBook::query()->increment('votes'),
            static fn() => MinimalBook::query()->decrement('votes'),
            static fn() => MinimalBook::query()->increment('id', 1, ['secret' => 'changed']),
            static fn() => MinimalBook::query()->decrement('id', 1, ['secret' => 'changed']),
        ] as $write) {
            $error = null;
            try {
                $write();
            } catch (\LogicException $caught) {
                $error = $caught;
            }
            self::assertInstanceOf(\LogicException::class, $error);
            self::assertStringContainsString('use the query builder', $error->getMessage());
            self::assertStringContainsString(MinimalBook::class, $error->getMessage());
        }
        self::assertSame([], $recording->recording->statements);
    }

    #[DataProvider('drivers')]
    public function testIncrementAndDecrementConvertTheirExtraFields(string $driver): void
    {
        $this->start($driver);
        foreach (['increment', 'decrement'] as $method) {
            Book::query()
                ->where('id', 1)
                ->{$method}('votes', 1, ['meta' => ['method' => $method]]);
            self::assertSame(['method' => $method], Book::find(1)->meta);
            $price = new Price();
            $price->amount = new Money(10);
            $price->save();
            Price::query()
                ->where('id', $price->id)
                ->{$method}('id', 10, ['amount' => new Money(20)]);
            self::assertSame(20, Price::query()->where('amount', 20)->first()->amount->cents);
        }
    }

    #[DataProvider('drivers')]
    public function testCursorPaginationDefaultsToTheMappedKeyAndRefusesNoKey(string $driver): void
    {
        $this->start($driver);
        $this->db->execute('create table tide_strings (uuid varchar(100) primary key, name varchar(100) not null)');
        $this->db->table('tide_strings')->insertMany([['uuid' => 'a', 'name' => 'A'], ['uuid' => 'b', 'name' => 'B']]);
        $query = ReviewString::query();
        $first = $query->cursorPaginate(1);
        self::assertSame('a', $first->items[0]->key);
        self::assertSame('b', $query->cursorPaginate(cursor: $first->nextCursor, perPage: 1)->items[0]->key);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no key property');
        KeylessReview::query()->cursorPaginate();
    }

    #[DataProvider('drivers')]
    public function testGroupedModelPredicatesComposeRelationsAndNestedOr(string $driver): void
    {
        $this->start($driver);
        $query = Category::query()
            ->where('id', '<=', 2)
            ->where(static function (ModelQuery $q): void {
                $q->whereHas('children')->orWhere(
                    static fn(ModelQuery $q) => $q->whereDoesntHave('children')->where('name', 'child'),
                );
            });
        self::assertSame([1, 2], $query->orderBy('id')->pluck('id'));
        $query = Category::query()
            ->where('id', 99)
            ->orWhere(static function (ModelQuery $q): void {
                $q->whereDoesntHave('children')->where('id', 3);
            });
        self::assertSame([3], $query->pluck('id'));
        self::assertSame(
            [1],
            Category::query()->tap(static fn(Query $q) => $q->where(static fn(Query $q) => $q->where('id', 1)))->pluck(
                'id',
            ),
        );
    }

    #[DataProvider('drivers')]
    public function testSelfRelationBulkWritesAreRefusedEvenThroughGroupsAndPaths(string $driver): void
    {
        $this->start($driver);
        $queries = [
            Category::query()->whereHas('children'),
            Category::query()->whereDoesntHave('parent'),
            Category::query()->whereHas('children.parent'),
            Category::query()->where(static fn(ModelQuery $q) => $q->whereHas('children')),
            Book::query()->whereHas('author.books'),
            Book::query()->whereHas('author', static fn(ModelQuery $q) => $q->whereHas('books')),
        ];
        foreach ($queries as $query) {
            self::assertIsInt($query->count());
            foreach (['update', 'increment', 'decrement', 'delete'] as $method) {
                $error = null;
                try {
                    match ($method) {
                        'update' => $query->update(['id' => 100]),
                        'increment' => $query->increment('id'),
                        'decrement' => $query->decrement('id'),
                        'delete' => $query->delete(),
                    };
                } catch (\LogicException $caught) {
                    $error = $caught;
                }
                self::assertInstanceOf(\LogicException::class, $error);
                self::assertStringContainsString('read the keys first', $error->getMessage());
                self::assertStringContainsString('whereIn()', $error->getMessage());
            }
        }
        self::assertSame(3, Category::query()->count());
        $keys = Category::query()->whereHas('children')->pluck('id');
        self::assertSame(2, Category::query()->whereIn('id', $keys)->update(['name' => 'safe']));
        self::assertSame(3, Book::query()->whereHas('author')->update(['title' => 'allowed']));
    }

    #[DataProvider('drivers')]
    public function testChunkingRefusesAnOrderBeforeItsCallback(string $driver): void
    {
        $this->start($driver);
        $calls = 0;
        $error = null;
        try {
            Book::query()
                ->orderByDesc('id')
                ->chunkById(2, static function () use (&$calls): void {
                    ++$calls;
                });
        } catch (\LogicException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(\LogicException::class, $error);
        self::assertStringContainsString('remove the other orderBy()', $error->getMessage());
        self::assertSame(0, $calls);
    }

    #[DataProvider('drivers')]
    public function testSingleRelationsHydrateOnlyTheWinnerPerParent(string $driver): void
    {
        $this->start($driver);
        $rows = [];
        for ($id = 3; $id <= 102; ++$id) {
            $rows[] = ['id' => $id, 'book_id' => $id <= 101 ? 1 : 2, 'code' => 'candidate'];
        }
        $this->db->table('tide_isbns')->insertMany($rows);
        CountedCode::$reads = 0;
        $books = CountedBook::query()->with('first', 'owner')->orderBy('id')->get();
        self::assertSame(4, CountedCode::$reads, 'One hydration per matching parent, for each single relation.');
        self::assertSame(1, $books[0]->first->id);
        self::assertSame(102, $books[1]->first->id);
        self::assertNull($books[2]->first);
        CountedCode::$reads = 0;
        CountedBook::load($books, 'first', static fn(ModelQuery $q) => $q->orderByDesc('id'));
        self::assertSame(2, CountedCode::$reads);
        self::assertSame(101, $books[0]->first->id);
    }
}

#[Table('tide_books')]
final class SnapshotColumn extends Model
{
    public int $id;
    #[Hidden]
    public string $secret;
    #[Json]
    #[ColumnName('meta')]
    public array $storedValues;
}

#[Table('tide_books')]
final class HiddenGetter extends Model
{
    public int $id;
    #[Hidden]
    public string $secret {
        get {
            throw new \RuntimeException($this->secret);
        }
    }
}

#[Table('tide_categories')]
final class RawNode extends Model
{
    public int $id {
        get => $this->id + 100;
    }
    public ?int $parent_id {
        get => $this->parent_id === null ? null : $this->parent_id + 100;
    }
    public string $name {
        get => strtoupper($this->name);
    }
    #[HasMany(self::class, 'parent_id')]
    public array $children;
    #[BelongsTo('parent_id')]
    public ?self $parent;
}

#[Table('tide_authors')]
final class PrivateAuthor extends Model
{
    public private(set) int $id;
    public protected(set) string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

#[Table('tide_categories')]
final class HookedRelation extends Model
{
    public int $id;
    public ?int $parent_id;
    #[BelongsTo('parent_id')]
    public ?Category $parent {
        set => $value;
    }
}

#[Table('tide_categories')]
final class PrivateRelation extends Model
{
    public int $id;
    public ?int $parent_id;
    #[BelongsTo('parent_id')]
    public private(set) ?Category $parent;
}

#[Table('tide_categories')]
final class ProtectedRelation extends Model
{
    public int $id;
    public ?int $parent_id;
    #[BelongsTo('parent_id')]
    public protected(set) ?Category $parent;
}

#[Table('tide_categories')]
final class VirtualRelation extends Model
{
    public int $id;
    public ?int $parent_id;
    #[BelongsTo('parent_id')]
    public ?Category $parent {
        get => null;
    }
}

#[Table('tide_categories')]
final class UnsafeVirtual extends Model
{
    public int $id;
    public ?int $parent_id;
    public string $name;
    public string $computed {
        get => throw new \RuntimeException('Unmapped getter ran.');
    }
    #[HasMany(Category::class, 'parent_id')]
    public array $children;
}

#[Table('tide_books')]
final class InterfaceDate extends Model
{
    public int $id;
    public ?\DateTimeInterface $published_at;
}

#[Table('tide_books')]
final class MixedDate extends Model
{
    public int $id;
    public mixed $published_at;
}

#[Table('tide_books')]
final class MinimalBook extends Model
{
    public int $id;
    public string $title;
}

#[Table('tide_strings', key: 'uuid')]
final class ReviewString extends Model
{
    #[ColumnName('uuid')]
    public string $key;
    public string $name;
}

#[Table('tide_books')]
final class KeylessReview extends Model
{
    public string $title;
}

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CountedCode implements Cast
{
    public static int $reads = 0;

    public function read(mixed $value): mixed
    {
        ++self::$reads;
        return $value;
    }

    public function write(mixed $value): mixed
    {
        return $value;
    }
}

#[Table('tide_isbns')]
final class CountedIsbn extends Model
{
    public int $id;
    public int $book_id;
    #[CountedCode]
    public string $code;
}

#[Table('tide_books')]
final class CountedBook extends Model
{
    public int $id;
    #[HasOne('book_id')]
    public ?CountedIsbn $first;
    #[BelongsTo('id', 'book_id')]
    public ?CountedIsbn $owner;
}

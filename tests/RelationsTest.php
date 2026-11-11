<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\ModelMap;
use Crab\Tide\ModelQuery;
use Crab\Tide\Tests\Support\Author;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\Category;
use Crab\Tide\Tests\Support\Chapter;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RelationsTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testEveryKindLoadsInOrderAndSharesRelatedObjects(string $driver): void
    {
        $this->start($driver);
        $connection = $this->recording();
        $books = new ModelQuery(ModelMap::of(Book::class), $connection)
            ->with('author', 'chapters', 'isbn', 'tags')
            ->orderBy('id')
            ->get();
        self::assertCount(5, $connection->statements);
        self::assertSame('Ada', $books[0]->author->name);
        self::assertSame($books[0]->author, $books[1]->author);
        self::assertSame([1, 2], array_column($books[0]->chapters, 'id'));
        self::assertSame('first', $books[0]->isbn->code);
        self::assertSame([1, 2], array_column($books[0]->tags, 'id'));
        self::assertSame($books[0]->tags[0], $books[1]->tags[0]);
        self::assertNull($books[3]->author);
        self::assertNull($books[3]->isbn);
        self::assertSame([], $books[3]->tags);
        self::assertSame([], $books[3]->chapters);
    }

    #[DataProvider('drivers')]
    public function testNestedPathsConstraintsAndRepeatedNames(string $driver): void
    {
        $this->start($driver);
        $connection = $this->recording();
        $books = new ModelQuery(ModelMap::of(Book::class), $connection)
            ->with(
                'chapters.notes',
                static fn(ModelQuery $query) => $query->where('body', 'A note'),
                'chapters',
                static fn(ModelQuery $query) => $query->orderByDesc('id'),
                'chapters.notes',
            )
            ->orderBy('id')
            ->get();
        self::assertCount(3, $connection->statements);
        self::assertSame([2, 1], array_column($books[0]->chapters, 'id'));
        self::assertSame('A note', $books[0]->chapters[0]->notes[0]->body);
        self::assertSame([], $books[0]->chapters[1]->notes);
        Book::load($books, 'isbn', static fn(ModelQuery $query) => $query->orderByDesc('id'));
        self::assertSame('second', $books[0]->isbn->code);
        Book::load($books, 'isbn');
        self::assertSame('first', $books[0]->isbn->code);
    }

    #[DataProvider('drivers')]
    public function testRelatedQueriesForEveryKindAndNullForeignKey(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        self::assertSame('Ada', $book->related('author')->first()->name);
        self::assertSame('second', $book->related('isbn')->orderByDesc('id')->first()->code);
        self::assertSame([2], array_column($book->related('chapters')->orderByDesc('id')->limit(1)->get(), 'id'));
        self::assertSame(['PHP', 'SQL'], $book->related('tags')->orderBy('id')->pluck('name'));
        self::assertFalse(Book::find(4)->related('author')->exists());
        self::assertFalse(isset($book->author));
    }

    #[DataProvider('drivers')]
    public function testHasAndDoesntHaveForEachKindNestedAndSelfRelations(string $driver): void
    {
        $this->start($driver);
        self::assertSame([1, 2, 3], array_column(Book::query()->whereHas('author')->orderBy('id')->get(), 'id'));
        self::assertSame([4], array_column(Book::query()->whereDoesntHave('author')->get(), 'id'));
        self::assertSame([1], array_column(Book::query()->whereHas('isbn')->get(), 'id'));
        self::assertSame([1, 2], array_column(Book::query()->whereHas('chapters')->orderBy('id')->get(), 'id'));
        self::assertSame(
            [1],
            array_column(
                Book::query()->whereHas('tags', static fn(ModelQuery $query) => $query->where('name', 'SQL'))->get(),
                'id',
            ),
        );
        self::assertSame([1], array_column(Book::query()->whereHas('chapters.notes')->get(), 'id'));
        self::assertSame(
            [2, 3, 4],
            array_column(Book::query()->whereDoesntHave('chapters.notes')->orderBy('id')->get(), 'id'),
        );
        self::assertSame([2, 3], array_column(Category::query()->whereHas('parent')->orderBy('id')->get(), 'id'));
        self::assertSame(
            [3],
            array_column(
                Category::query()->whereHas('parent.parent', static fn(ModelQuery $query) => $query->where(
                    'name',
                    'root',
                ))->get(),
                'id',
            ),
        );
        self::assertSame([1], array_column(Category::query()->whereHas('children.children')->get(), 'id'));
        self::assertSame([1], array_column(Category::query()->whereDoesntHave('parent')->get(), 'id'));
    }

    #[DataProvider('drivers')]
    public function testNoKeysNeedsNoQueryAndUnloadedAccessIsPhpError(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(4);
        self::assertFalse(isset($book->author));
        try {
            $book->author;
        } catch (\Error $error) {
            $message = $error->getMessage();
        }
        self::assertStringContainsString('must not be accessed before initialization', $message);
        $connection = $this->recording();
        $books = new ModelQuery(ModelMap::of(Book::class), $connection)
            ->where('id', 4)
            ->with('author')
            ->get();
        self::assertCount(1, $connection->statements);
        self::assertNull($books[0]->author);
        Book::load([], 'not-a-relation');
    }

    #[DataProvider('drivers')]
    public function testNonNullableMissingRelationRefusesTheValue(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_chapters')->insert(['book_id' => 999, 'title' => 'Orphan']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(Chapter::class . '::$book has no related row');
        Chapter::query()->with('book')->get();
    }

    #[DataProvider('drivers')]
    public function testKeysAreDeduplicatedAndBatchedAtOneThousand(string $driver): void
    {
        $this->start($driver);
        $this->db->transaction(static function (\Crab\Contracts\Database\Connection $tx): void {
            for ($id = 3; $id <= 1_003; ++$id) {
                $tx->table('tide_authors')->insert(['id' => $id, 'name' => 'Author']);
            }
        });
        $connection = $this->recording();
        $authors = new ModelQuery(ModelMap::of(Author::class), $connection)->with('books', 'books')->get();
        self::assertCount(1_003, $authors);
        self::assertCount(3, $connection->statements);
        self::assertCount(1_000, $connection->statements[1][1]);
        self::assertCount(3, $connection->statements[2][1]);
    }

    #[DataProvider('badLoads')]
    public function testInvalidLoadRequestsSayHowToFixThem(string $case, string $message): void
    {
        $this->start('sqlite');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        match ($case) {
            'closure' => Book::query()->with(static fn() => null),
            'name' => Book::query()->with('unknown'),
            'nested' => Book::query()->with('author.unknown')->get(),
            'limit' => Book::query()->with('chapters', static fn(ModelQuery $query) => $query->limit(1))->get(),
            'offset' => Book::query()->with('chapters', static fn(ModelQuery $query) => $query->offset(1))->get(),
            'page' => Book::query()->with('chapters', static fn(ModelQuery $query) => $query->forPage(1))->get(),
            'new' => Book::load([new Book()], 'author'),
            'mixed' => Book::load([Author::find(1)], 'author'),
            'related-new' => new Book()->related('author'),
            'related-name' => Book::find(1)->related('missing'),
        };
    }

    #[DataProvider('drivers')]
    public function testConstraintOrsCannotEscapeTheRelationAndConstraintsMayLoadNestedPaths(string $driver): void
    {
        $this->start($driver);
        $books = Book::query()
            ->orderBy('id')
            ->with('chapters', static fn(ModelQuery $query) => $query
                ->where('title', 'First')
                ->orWhere('title', 'Only')
                ->with('notes'))
            ->get();
        self::assertSame([1], array_column($books[0]->chapters, 'id'));
        self::assertSame([3], array_column($books[1]->chapters, 'id'));
        self::assertSame([], $books[2]->chapters);
        self::assertSame([], $books[1]->chapters[0]->notes);
        self::assertSame(
            [1, 2],
            array_column(
                Book::query()
                    ->whereHas('chapters', static fn(ModelQuery $query) => $query->where('title', 'First')->orWhere(
                        'title',
                        'Only',
                    ))
                    ->orderBy('id')
                    ->get(),
                'id',
            ),
        );
        self::assertSame(
            [1, 2],
            array_column(
                Book::query()
                    ->whereHas('author', static fn(ModelQuery $query) => $query->where('tide_authors.name', 'Ada'))
                    ->orderBy('id')
                    ->get(),
                'id',
            ),
        );
    }

    public static function badLoads(): array
    {
        return [
            ['closure',      'without a name'],
            ['name',         'use one of its declared relations'],
            ['nested',       'use one of its declared relations'],
            ['limit',        'use related('],
            ['offset',       'use related('],
            ['page',         'use related('],
            ['new',          'save it before loading'],
            ['mixed',        'pass one class at a time'],
            ['related-new',  'save it first'],
            ['related-name', 'use one of its declared relations'],
        ];
    }
}

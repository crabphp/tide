<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Query;
use Crab\Tide\ModelQuery;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final readonly class BookRow
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $author_name,
    ) {}
}

final readonly class BookTitle
{
    public function __construct(
        public int $id,
        public string $title,
    ) {}
}

/**
 * A model query ends as records for a shape a model is not, and a model never
 * drops a column it was given without a word.
 */
final class IntoTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testIntoKeepsTheModelsConditionsAndAllowsAJoin(string $driver): void
    {
        $this->start($driver);
        $rows = Book::query()
            ->where('votes', '>', 1)
            ->whereHas('author')
            ->orderBy('id')
            ->into(BookRow::class)
            ->leftJoin('tide_authors', 'tide_authors.id', '=', 'tide_books.author_id')
            ->select('tide_books.id', 'tide_books.title', 'tide_authors.name as author_name')
            ->get();
        self::assertEquals([new BookRow(2, 'Beta', 'Ada'), new BookRow(3, 'Gamma', 'Grace')], $rows);

        // Without select(), the record's own columns are read, from the model's table.
        self::assertEquals([new BookTitle(1, 'Alpha')], Book::query()->where('id', 1)->into(BookTitle::class)->get());
        self::assertSame(4, Book::query()->into(BookTitle::class)->count());
    }

    #[DataProvider('drivers')]
    public function testAJsonPathConditionStaysTheModelsAfterAJoin(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_books')->where('id', 2)->update(['meta' => '{"colour":"blue"}']);
        $sql = Book::query()->where('meta->colour', 'blue')->toSql();
        self::assertStringContainsString('`tide_books`.`meta`', $sql);
        // tide_chapters has no meta, so join a table that does: the books again, under another name.
        $rows = Book::query()
            ->where('meta->colour', 'blue')
            ->into(BookTitle::class)
            ->join('tide_books as other', 'other.id', '=', 'tide_books.id')
            ->select('tide_books.id', 'tide_books.title')
            ->get();
        self::assertEquals([new BookTitle(2, 'Beta')], $rows);
    }

    #[DataProvider('drivers')]
    public function testIntoOnARelatedQueryReadsOnlyThatParentsRows(string $driver): void
    {
        $this->start($driver);
        $titles = Book::find(1)->related('chapters')->orderBy('id')->into(BookTitle::class)->get();
        self::assertSame(['First', 'Second'], array_map(static fn(BookTitle $row): string => $row->title, $titles));
    }

    public function testIntoRefusesWhatOnlyModelsHave(): void
    {
        $this->start('sqlite');
        foreach ([
            'loads relations with with(), and a record has none' => static fn(): Query => Book::query()
                ->with('author')
                ->into(BookTitle::class),
            'chose model columns with select() or except()' => static fn(): Query => Book::query()
                ->select('title')
                ->into(BookTitle::class),
        ] as $message => $make) {
            try {
                $make();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    #[DataProvider('drivers')]
    public function testTapTakesConditionsButNoJoinAndNoOtherColumns(string $driver): void
    {
        $this->start($driver);
        // A condition through tap() keeps the rows and their shape, and is fine.
        self::assertCount(
            1,
            Book::query()->tap(static fn(Query $query): Query => $query->whereRaw('votes = ?', [2]))->get(),
        );

        // whereHas() filters by related rows, as a join would, without one row per related row.
        self::assertSame(
            ['Alpha', 'Beta'],
            Book::query()
                ->whereHas('author', static fn(ModelQuery $query): ModelQuery => $query->where('name', 'Ada'))
                ->orderBy('id')
                ->pluck('title'),
        );

        foreach ([
            // Even only to filter: a book joined to its chapters would come back once for each.
            'a join that filters' => [
                static fn(Query $query): Query => $query->join(
                    'tide_chapters',
                    'tide_chapters.book_id',
                    '=',
                    'tide_books.id',
                ),
                'was given a join in tap()',
            ],
            // The joined id would replace the book's own, and save() would then write another book.
            'a joined key of the same name' => [
                static fn(Query $query): Query => $query
                    ->join('tide_authors', 'tide_authors.id', '=', 'tide_books.author_id')
                    ->addSelect('tide_authors.id'),
                'was given a join in tap()',
            ],
            'an expression' => [
                static fn(Query $query): Query => $query->selectRaw('votes * 2 as doubled'),
                'had the columns it reads changed in tap()',
            ],
            'a narrower list' => [
                static fn(Query $query): Query => $query->select('tide_books.id'),
                'had the columns it reads changed in tap()',
            ],
        ] as $case => [$tap, $message]) {
            try {
                Book::query()->where('id', 2)->tap($tap);
                self::fail("Not refused: {$case}.");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $case);
            }
        }
    }
}

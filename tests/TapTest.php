<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Query;
use Crab\Tide\ModelQuery;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * tap() takes what keeps a model query's rows and their shape, a condition or a
 * lock, and refuses a join or a change to the columns it reads.
 */
final class TapTest extends DatabaseTestCase
{
    public function testAJsonPathConditionIsWrittenWithTheModelsTable(): void
    {
        $this->start('sqlite');
        self::assertStringContainsString('`tide_books`.`meta`', Book::query()->where('meta->colour', 'blue')->toSql());
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

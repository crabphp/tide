<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\ModelQuery;
use Crab\Tide\Tests\Support\Author;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Status;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Totals and values of a model's own columns, read as its properties hold
 * them, over the rows its query chooses, relation conditions included.
 */
final class AggregatesTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testTotalsKeepTheQuerysConditionsRelationsIncluded(string $driver): void
    {
        $this->start($driver);
        self::assertSame(4, Book::query()->count());
        self::assertSame(10, Book::query()->sum('votes'));
        self::assertSame(2.5, Book::query()->avg('votes'));
        self::assertSame(1, Book::query()->min('votes'));
        self::assertSame(4, Book::query()->max('votes'));
        // The builder has no whereHas(): this is the model query's to do.
        $ada = static fn(ModelQuery $query): ModelQuery => $query->where('name', 'Ada');
        self::assertSame(3, Book::query()->whereHas('author', $ada)->sum('votes'));
        self::assertSame(3, Author::find(1)->related('books')->sum('votes'));
        self::assertNull(Book::query()->where('id', 99)->max('votes'), 'No row, no largest value.');
        self::assertNull(Book::query()->where('id', 99)->avg('votes'));
        self::assertSame(0, Book::query()->where('id', 99)->sum('votes'));
    }

    #[DataProvider('drivers')]
    public function testValuesComeBackAsTheirPropertiesHoldThem(string $driver): void
    {
        $this->start($driver);
        $this->db
            ->table('tide_books')
            ->where('id', 2)
            ->update([
                'status' => 'published',
                'published_at' => '2026-03-04 05:06:07',
                'meta' => '{"pages":12}',
            ]);
        self::assertSame(['Alpha', 'Beta'], Book::query()->where('author_id', 1)->orderBy('id')->pluck('title'));
        self::assertSame(
            [1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma', 4 => 'Delta'],
            Book::query()->orderBy('id')->pluck('title', 'id'),
        );
        self::assertSame(
            [Status::Draft, Status::Published],
            Book::query()->whereIn('id', [1, 2])->orderBy('id')->pluck('status'),
        );
        self::assertSame(['pages' => 12], Book::query()->where('id', 2)->value('meta'));
        self::assertSame(Status::Published, Book::query()->where('id', 2)->value('status'));
        self::assertNull(Book::query()->where('id', 99)->value('title'));

        $latest = Book::query()->max('published_at');
        self::assertInstanceOf(\DateTimeImmutable::class, $latest);
        self::assertSame('2026-03-04 05:06:07', $latest->format('Y-m-d H:i:s'));
        self::assertNull(Book::query()->where('id', 1)->value('published_at'), 'A null column is null.');
    }

    public function testOnlyTheModelsOwnColumnsAreRead(): void
    {
        $this->start('sqlite');
        foreach ([
            'sum() names "title", which is not a number' => static fn() => Book::query()->sum('title'),
            'avg() names "status", which is not a number' => static fn() => Book::query()->avg('status'),
            'max() names "nope", which is not one of its columns' => static fn() => Book::query()->max('nope'),
            'pluck() names "votes * 2"' => static fn() => Book::query()->pluck('votes * 2'),
            'value() names "tide_authors.name"' => static fn() => Book::query()->value('tide_authors.name'),
            'pluck() is keyed by "status", which is not an int or string column' => static fn() => Book::query()->pluck(
                'title',
                'status',
            ),
        ] as $message => $read) {
            try {
                $read();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(
            ['Alpha'],
            Book::query()->where('id', 1)->pluck('tide_books.title'),
            'A column may be named with its table.',
        );
    }
}

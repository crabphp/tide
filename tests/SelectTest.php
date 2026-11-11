<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\Model;
use Crab\Tide\ModelQuery;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Author;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Tag;
use PHPUnit\Framework\Attributes\DataProvider;

#[Table('tide_books')]
final class DefaultedBook extends Model
{
    public int $id;
    public string $title;
    public int $votes = 0;
}

#[Table('tide_books')]
final class HookedBook extends Model
{
    public int $id;
    public string $title = 'untitled' {
        set(string $value) => trim($value);
    }
}

/**
 * A query that reads some of a model's columns: the others are uninitialised,
 * never a default or null that looks like the row's value, and never written.
 */
final class SelectTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testOnlyTheChosenColumnsAndTheKeyAreReadAndTheRestAreUninitialised(string $driver): void
    {
        $this->start($driver);
        $query = Book::query()->select('title')->orderBy('id');
        $sql = $query->toSql();
        self::assertStringContainsString('`tide_books`.`id`', $sql, 'The key is always read.');
        self::assertStringContainsString('`tide_books`.`title`', $sql);
        self::assertStringNotContainsString('`votes`', $sql);

        $book = $query->first();
        self::assertSame([1, 'Alpha'], [$book->id, $book->title]);
        self::assertFalse(new \ReflectionProperty($book, 'votes')->isInitialized($book));
        self::assertSame('{"id":1,"title":"Alpha"}', json_encode($book, JSON_THROW_ON_ERROR));
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('must not be accessed before initialization');
        $book->votes;
    }

    #[DataProvider('drivers')]
    public function testExceptReadsEveryOtherColumnAndEveryReadingCallHonoursTheChoice(string $driver): void
    {
        $this->start($driver);
        $sql = Book::query()->except('meta', 'secret')->toSql();
        self::assertStringNotContainsString('`meta`', $sql);
        self::assertStringNotContainsString('`secret`', $sql);
        self::assertStringContainsString('`votes`', $sql);

        $query = Book::query()->select('id', 'title');
        self::assertSame('Beta', $query->find(2)->title);
        self::assertSame(
            ['Alpha', 'Beta'],
            array_column(json_decode(json_encode($query->orderBy('id')->paginate(2)->items), true), 'title'),
        );
        self::assertCount(2, $query->simplePaginate(2)->items);
        $seen = [];
        Book::query()
            ->select('title')
            ->chunkById(3, static function (array $books) use (&$seen): void {
                foreach ($books as $book) {
                    $seen[] = $book->title;
                }
            });
        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Delta'], $seen);
    }

    #[DataProvider('drivers')]
    public function testSavingAPartialModelWritesOnlyWhatChangedAndRefreshReadsTheWholeRow(string $driver): void
    {
        $this->start($driver);
        $book = Book::query()->select('title')->find(2);
        $book->title = 'Beta, again';
        $book->save();
        self::assertSame(
            ['title' => 'Beta, again', 'votes' => 2, 'author_id' => 1, 'secret' => 'hidden'],
            $this->db->table('tide_books')->select('title', 'votes', 'author_id', 'secret')->where('id', 2)->first(),
            'Columns the query did not read are left as the row has them.',
        );

        $book->votes = 9;
        $book->save();
        self::assertSame(
            9,
            $this->db->table('tide_books')->where('id', 2)->value('votes'),
            'An unread column that is assigned is written.',
        );

        $book->refresh();
        self::assertSame([9, 1, 'hidden'], [$book->votes, $book->author_id, $book->secret]);
    }

    #[DataProvider('drivers')]
    public function testAnUnreadColumnWithADefaultIsUninitialisedAndNeverOverwrittenByTheDefault(string $driver): void
    {
        $this->start($driver);
        $book = DefaultedBook::query()->select('title')->find(3);
        self::assertFalse(new \ReflectionProperty($book, 'votes')->isInitialized($book), 'Not the class default 0.');
        $book->title = 'Gamma, again';
        $book->save();
        self::assertSame(3, $this->db->table('tide_books')->where('id', 3)->value('votes'));
    }

    #[DataProvider('drivers')]
    public function testRelationsAddTheKeysTheyAreMatchedBy(string $driver): void
    {
        $this->start($driver);
        // The book's author_id, though not chosen, is read to match its author.
        $books = Book::query()->select('title')->with('author', 'chapters', 'tags')->orderBy('id')->get();
        self::assertSame(
            ['Ada', 'Ada', 'Grace', null],
            array_map(static fn(Book $book): ?string => $book->author?->name, $books),
        );
        self::assertSame(1, $books[0]->author_id);
        self::assertSame(
            ['First', 'Second'],
            array_map(static fn($chapter): string => $chapter->title, $books[0]->chapters),
        );
        self::assertSame(['PHP', 'SQL'], array_map(static fn(Tag $tag): string => $tag->name, $books[0]->tags));

        // A constrained relation may choose its columns too; the foreign key and nested keys are still read.
        $authors = Author::query()
            ->with('books', static fn(ModelQuery $query): ModelQuery => $query->select('title'))
            ->with('books.author')
            ->orderBy('id')
            ->get();
        self::assertSame(
            ['Alpha', 'Beta'],
            array_map(static fn(Book $book): string => $book->title, $authors[0]->books),
        );
        self::assertSame('Ada', $authors[0]->books[0]->author->name);
        self::assertSame(
            ['id', 'title', 'author_id', 'author'],
            array_keys(json_decode(json_encode($authors[0]->books[0], JSON_THROW_ON_ERROR), true)),
            'Its JSON holds what was read: the chosen column, the keys and the loaded relation.',
        );
    }

    #[DataProvider('drivers')]
    public function testACursorPageReadsTheColumnsItIsOrderedBy(string $driver): void
    {
        $this->start($driver);
        $first = Book::query()->select('title')->orderByDesc('votes')->orderBy('id')->cursorPaginate(2);
        self::assertSame(['Delta', 'Gamma'], array_map(static fn(Book $book): string => $book->title, $first->items));
        $next = Book::query()
            ->select('title')
            ->orderByDesc('votes')
            ->orderBy('id')
            ->cursorPaginate(2, $first->nextCursor);
        self::assertSame(['Beta', 'Alpha'], array_map(static fn(Book $book): string => $book->title, $next->items));

        $plain = Book::query()->select('title')->orderByDesc('votes')->get()[0];
        self::assertFalse(
            new \ReflectionProperty($plain, 'votes')->isInitialized($plain),
            'Only a cursor reads its order.',
        );
    }

    #[DataProvider('drivers')]
    public function testLoadingARelationOnAModelReadWithoutItsKeySaysToRefresh(string $driver): void
    {
        $this->start($driver);
        $books = Book::query()->select('title')->orderBy('id')->get();
        $this->expectExceptionMessage('needs the uninitialized property $author_id');
        Book::load($books, 'author');
    }

    public function testAHookedColumnWithADefaultIsAlwaysRead(): void
    {
        $this->start('sqlite');
        // PHP cannot unset a hooked property, so leaving it out would leave 'untitled' looking like the row's title.
        self::assertStringContainsString('`tide_books`.`title`', HookedBook::query()->select('id')->toSql());
        self::assertSame('Alpha', HookedBook::query()->select('id')->find(1)->title);
    }

    public function testAColumnTheModelDoesNotDeclareOrAnEmptyChoiceIsRefused(): void
    {
        $this->start('sqlite');
        foreach ([
            'select() names "nope", which is not one of its columns' => static fn() => Book::query()->select(
                'title',
                'nope',
            ),
            'except() names "nope"' => static fn() => Book::query()->except('nope'),
            'select() needs at least one column' => static fn() => Book::query()->select(),
            'except() needs at least one column' => static fn() => Book::query()->except(),
        ] as $message => $make) {
            try {
                $make();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertStringContainsString(
            '`tide_books`.`title`',
            Book::query()->select('tide_books.title')->toSql(),
            'A column may be named with the model\'s table.',
        );
    }
}

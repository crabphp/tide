<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\Count;
use Crab\Tide\Model;
use Crab\Tide\ModelMap;
use Crab\Tide\ModelQuery;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Author;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\BookFactory;
use Crab\Tide\Tests\Support\BookQuery;
use Crab\Tide\Tests\Support\Category;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Money;
use Crab\Tide\Tests\Support\Price;
use Crab\Tide\Tests\Support\Status;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConvenienceTest extends DatabaseTestCase
{
    // withCount()

    #[DataProvider('drivers')]
    public function testWithCountReadsEachModelsCountInOneStatement(string $driver): void
    {
        $this->start($driver);
        $books = Book::query()->withCount('chapters', 'tags')->orderBy('id')->get();
        self::assertSame([2, 1, 0, 0], array_map(static fn(Book $b): int => $b->chapters_count, $books));
        self::assertSame([2, 1, 0, 0], array_map(static fn(Book $b): int => $b->tags_count, $books));
        self::assertSame(2, json_decode(json_encode($books[0]), true)['chapters_count']);
    }

    #[DataProvider('drivers')]
    public function testACountIsUninitialisedUnlessAskedForAndNeverSaved(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        self::assertFalse(isset($book->chapters_count));
        self::assertArrayNotHasKey('chapters_count', json_decode(json_encode($book), true));
        $counted = Book::query()->withCount('chapters')->find(1);
        $counted->chapters_count = 99;
        self::assertFalse($counted->isDirty());
        $counted->title = 'Changed';
        $counted->save();
        self::assertSame('Changed', Book::find(1)->title);
    }

    #[DataProvider('drivers')]
    public function testACountWithAConditionFillsTheNamedProperty(string $driver): void
    {
        $this->start($driver);
        $authors = Author::query()
            ->withCount('books_count', 'popular_books_count', static fn(ModelQuery $q) => $q->where('votes', '>=', 2))
            ->orderBy('id')
            ->get();
        self::assertSame([2, 1], [$authors[0]->books_count, $authors[1]->books_count]);
        self::assertSame([1, 1], [$authors[0]->popular_books_count, $authors[1]->popular_books_count]);
    }

    #[DataProvider('drivers')]
    public function testARelationWithSeveralCountsMustBeNamedByProperty(string $driver): void
    {
        $this->start($driver);
        $this->expectExceptionMessage(
            'several counts of "books"; name the property: books_count or popular_books_count',
        );
        Author::query()->withCount('books');
    }

    #[DataProvider('drivers')]
    public function testCountsSurviveSelectPagesAndASelfRelation(string $driver): void
    {
        $this->start($driver);
        $page = Book::query()->withCount('chapters')->select('title')->orderBy('id')->paginate(2);
        self::assertSame(4, $page->total);
        self::assertSame(2, $page->items[0]->chapters_count);
        self::assertSame(1, Book::query()->select('title')->withCount('chapters')->find(2)->chapters_count);
        self::assertSame(4, Book::query()->withCount('chapters')->count());
        self::assertTrue(Book::query()->withCount('chapters')->exists());
        $tree = Category::query()->withCount('children')->orderBy('id')->get();
        self::assertSame([1, 1, 0], array_map(static fn(Category $c): int => $c->children_count, $tree));
    }

    #[DataProvider('drivers')]
    public function testCountsLoadInsideARelationConstraint(string $driver): void
    {
        $this->start($driver);
        $ada = Author::query()
            ->with('books', static fn(BookQuery $q) => $q->withCount('chapters'))
            ->find(1);
        self::assertSame([2, 1], array_map(static fn(Book $b): int => $b->chapters_count, $ada->books));
    }

    public function testACountMustBeAPlainIntOfACountableRelation(): void
    {
        foreach ([
            CountWithDefault::class => 'declare it public int $books_count;',
            CountOfNothing::class => 'which is not one of its relations',
        ] as $class => $message) {
            try {
                ModelMap::of($class);
                self::fail($class);
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    #[DataProvider('drivers')]
    public function testAnUnknownCountSaysHowToDeclareIt(string $driver): void
    {
        $this->start($driver);
        $this->expectExceptionMessage("declare #[Count('isbn')] public int \$isbn_count;");
        Book::query()->withCount('isbn');
    }

    // save() with values

    #[DataProvider('drivers')]
    public function testSaveWithValuesAssignsThemAndWritesOnlyThose(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        $book->save(['title' => 'Renamed', 'author_id' => 2]);
        self::assertSame('Renamed', $book->title);
        $fresh = Book::find(1);
        self::assertSame(['Renamed', 2], [$fresh->title, $fresh->author_id]);
        $new = new Book('New')->save(['votes' => 7]);
        self::assertSame(7, Book::find($new->id)->votes);
    }

    #[DataProvider('drivers')]
    public function testSaveRefusesANameThatIsNoColumnBeforeAssigningAny(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        foreach (['author', 'chapters_count', 'titel'] as $name) {
            try {
                $book->save(['title' => 'Changed', $name => null]);
                self::fail($name);
            } catch (\LogicException $error) {
                self::assertStringContainsString(
                    "names \"{$name}\", which is not one of its columns",
                    $error->getMessage(),
                );
            }
            self::assertSame('Alpha', $book->title);
        }
    }

    #[DataProvider('drivers')]
    public function testAValueOfTheWrongTypeLeavesTheModelAsItWas(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        try {
            $book->save(['title' => 'Changed', 'votes' => 'many']);
            self::fail('A string was assigned to an int.');
        } catch (\TypeError) {
        }
        self::assertSame('Alpha', $book->title);
        self::assertFalse($book->isDirty());
        $partial = Book::query()->select('title')->find(1);
        try {
            $partial->save(['votes' => 5, 'author_id' => 'x']);
        } catch (\TypeError) {
        }
        self::assertFalse(isset($partial->votes));
    }

    // isDirty(), dirty(), original()

    #[DataProvider('drivers')]
    public function testDirtyIsWhatSaveWouldWrite(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        self::assertFalse($book->isDirty());
        self::assertSame([], $book->dirty());
        $book->title = 'Alpha';
        self::assertFalse($book->isDirty());
        $book->title = 'Omega';
        $book->status = Status::Published;
        self::assertTrue($book->isDirty());
        self::assertTrue($book->isDirty('votes', 'title'));
        self::assertFalse($book->isDirty('votes'));
        self::assertSame(['title' => 'Omega', 'status' => Status::Published], $book->dirty());
        self::assertSame('Alpha', $book->original('title'));
        self::assertSame(Status::Draft, $book->original('status'));
        $book->save();
        self::assertFalse($book->isDirty());
        self::assertSame('Omega', $book->original('title'));
    }

    #[DataProvider('drivers')]
    public function testANewModelIsDirtyInEveryColumnItHasAndHasNoOriginal(string $driver): void
    {
        $this->start($driver);
        $book = new Book('Fresh', 1);
        self::assertTrue($book->isDirty('title'));
        self::assertSame(['title' => 'Fresh', 'author_id' => 1], $book->dirty());
        $this->expectExceptionMessage('has nothing for a new model');
        $book->original('title');
    }

    #[DataProvider('drivers')]
    public function testOriginalGivesACastsObjectAndKnowsWhatWasNotRead(string $driver): void
    {
        $this->start($driver);
        $price = new Price();
        $price->amount = new Money(100);
        $price->save();
        $price->amount->cents = 150;
        self::assertTrue($price->isDirty('amount'));
        self::assertEquals(new Money(100), $price->original('amount'));
        $this->expectExceptionMessage("original('votes') has no value: the query did not read it");
        Book::query()->select('title')->find(1)->original('votes');
    }

    #[DataProvider('drivers')]
    public function testIsDirtyNamesOnlyColumns(string $driver): void
    {
        $this->start($driver);
        $this->expectExceptionMessage('isDirty() names "author", which is not one of its columns');
        Book::find(1)->isDirty('author');
    }

    // firstOrNew(), firstOrCreate(), updateOrCreate()

    #[DataProvider('drivers')]
    public function testFirstOrNewAndFirstOrCreate(string $driver): void
    {
        $this->start($driver);
        $found = Book::query()
            ->where('title', 'Beta')
            ->firstOrNew(static fn() => new Book('Beta'));
        self::assertSame(2, $found->id);
        $made = Book::query()
            ->where('title', 'Zeta')
            ->firstOrNew(static fn() => new Book('Zeta'));
        self::assertFalse($made->stored());
        $created = Book::query()
            ->where('title', 'Zeta')
            ->firstOrCreate(static fn() => new Book('Zeta'));
        self::assertTrue($created->stored());
        $again = Book::query()
            ->where('title', 'Zeta')
            ->firstOrCreate(static fn() => new Book('Zeta'));
        self::assertSame($created->id, $again->id);
        self::assertSame(5, Book::query()->count());
    }

    #[DataProvider('drivers')]
    public function testUpdateOrCreateChangesTheFoundOrTheMadeModelAndSaves(string $driver): void
    {
        $this->start($driver);
        $updated = Book::query()
            ->where('title', 'Beta')
            ->updateOrCreate(static fn() => new Book('Beta'), static fn(Book $b) => $b->votes = 20);
        self::assertSame(2, $updated->id);
        self::assertSame(20, Book::find(2)->votes);
        $created = Book::query()
            ->where('title', 'Eta')
            ->updateOrCreate(static fn() => new Book('Eta'), static fn(Book $b) => $b->votes = 30);
        self::assertSame(30, Book::find($created->id)->votes);
    }

    #[DataProvider('drivers')]
    public function testTheClosureMustMakeANewModelOfTheQuerysClass(string $driver): void
    {
        $this->start($driver);
        foreach ([static fn() => new Author(), static fn() => Book::find(1)] as $make) {
            try {
                Book::query()->where('title', 'Nowhere')->firstOrCreate($make);
                self::fail('The closure gave the wrong model.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('write fn () => new Book(...)', $error->getMessage());
            }
        }
    }

    // Query scopes

    #[DataProvider('drivers')]
    public function testAModelsOwnQueryClassChainsAnywhereAndInRelations(string $driver): void
    {
        $this->start($driver);
        $ada = Author::find(1);
        self::assertInstanceOf(BookQuery::class, Book::query());
        self::assertSame(['Beta'], Book::query()->by($ada)->popular(2)->pluck('title'));
        self::assertSame(['Gamma', 'Delta'], Book::query()->orderBy('id')->popular()->pluck('title'));
        self::assertInstanceOf(BookQuery::class, $ada->related('books'));
        self::assertSame(['Beta'], $ada->related('books')->popular(2)->pluck('title'));
        $loaded = Author::query()
            ->with('books', static fn(BookQuery $q) => $q->popular(2))
            ->find(1);
        self::assertSame(['Beta'], array_map(static fn(Book $b): string => $b->title, $loaded->books));
        self::assertSame(
            [2],
            Author::query()
                ->whereHas('books', static fn(BookQuery $q) => $q->popular(3))
                ->pluck('id'),
        );
    }

    #[DataProvider('drivers')]
    public function testAQueryClassIsFoundByNameOrNamedInTheAttribute(string $driver): void
    {
        $this->start($driver);
        self::assertInstanceOf(BookQuery::class, Book::query()); // BookQuery beside Book
        self::assertInstanceOf(ModelQuery::class, Author::query()); // none: the plain query
        self::assertSame(ModelQuery::class, Author::query()::class);
        self::assertSame(ModelQuery::class, ModelMap::of(Plain::class)->queryClass); // PlainQuery is no ModelQuery
        self::assertSame(BookQuery::class, ModelMap::of(Named::class)->queryClass);
    }

    public function testTheQueryClassMustExtendModelQuery(): void
    {
        $this->expectExceptionMessage('names stdClass as its query; name a class extending ' . ModelQuery::class);
        ModelMap::of(WrongQuery::class);
    }

    // latest(), oldest()

    #[DataProvider('drivers')]
    public function testLatestAndOldestOrderByTheCreationTimeOrAColumn(string $driver): void
    {
        $this->start($driver);
        self::assertStringContainsString(
            'order by "tide_books"."created_at" desc',
            strtolower(str_replace('`', '"', Dated::query()->latest()->toSql())),
        );
        self::assertStringContainsString(
            'order by "tide_books"."created_at" asc',
            strtolower(str_replace('`', '"', Dated::query()->oldest()->toSql())),
        );
        self::assertSame('Delta', Book::query()->latest('votes')->first()->title);
        self::assertSame('Alpha', Book::query()->oldest('votes')->first()->title);
        $this->expectExceptionMessage(
            "latest() has no creation time to order by; name a column, latest('published_at')",
        );
        Book::query()->latest();
    }

    // Factories

    #[DataProvider('drivers')]
    public function testAFactoryMakesAndCreatesWithStatesAndOwners(string $driver): void
    {
        $this->start($driver);
        $grace = Author::find(2);
        $books = BookFactory::new()
            ->count(3)
            ->for('author', $grace)
            ->state(['votes' => 9])
            ->state(static fn(Book $b, int $n) => $b->title .= '!')
            ->create();
        self::assertCount(3, $books);
        foreach ($books as $book) {
            self::assertTrue($book->stored());
            self::assertSame(9, Book::find($book->id)->votes);
            self::assertSame(2, $book->author_id);
            self::assertSame($grace, $book->author);
            self::assertStringEndsWith('!', $book->title);
        }
        self::assertCount(3, array_unique(array_map(static fn(Book $b): string => $b->title, $books)));
        self::assertFalse(BookFactory::new()->makeOne()->stored());
        self::assertTrue(BookFactory::new()->createOne()->stored());
        self::assertSame(8, Book::query()->count());
    }

    public function testAFactoryIsNeverChangedAndStatesNameColumns(): void
    {
        $base = BookFactory::new();
        $base->count(5)->state(['votes' => 1]);
        self::assertCount(1, $base->make());
        $this->expectExceptionMessage('state() names "titel", which is not one of');
        BookFactory::new()->state(['titel' => 'x'])->make();
    }
}

#[Table('tide_books')]
final class Dated extends Model
{
    public int $id;
    public ?\DateTimeImmutable $created_at = null;
}

#[Table('tide_books', query: \stdClass::class)]
final class WrongQuery extends Model
{
    public int $id;
}

#[Table('tide_authors')]
final class CountWithDefault extends Model
{
    public int $id;
    #[\Crab\Tide\HasMany(Book::class, 'author_id')]
    public array $books;
    #[Count('books')]
    public int $books_count = 0;
}

#[Table('tide_authors')]
final class CountOfNothing extends Model
{
    public int $id;
    #[Count('books')]
    public int $books_count;
}

/**
 * A class named like a model's query that is no ModelQuery, a search form's, is left alone.
 */
final class PlainQuery
{
    public string $q = '';
}

#[Table('tide_books')]
final class Plain extends Model
{
    public int $id;
}

#[Table('tide_books', query: BookQuery::class)]
final class Named extends Model
{
    public int $id;
}

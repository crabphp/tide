<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\BelongsToMany;
use Crab\Tide\Model;
use Crab\Tide\ModelQuery;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\Category;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the second review found, in the forms an application would write.
 */
final class SecondReviewTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testAQueryIsNotRunOrCopiedInsideAConditionClosure(string $driver): void
    {
        $this->start($driver);
        $errors = [];
        foreach ([
            static fn(ModelQuery $group) => $group->where('id', 2)->update(['title' => 'everything']),
            static fn(ModelQuery $group) => $group->where('id', 2)->delete(),
            static fn(ModelQuery $group) => $group->where('id', 2)->get(),
            static fn(ModelQuery $group) => clone $group,
        ] as $inside) {
            try {
                Book::query()->where($inside);
                $errors[] = null;
            } catch (\LogicException $error) {
                $errors[] = str_contains($error->getMessage(), 'run the query after the closure');
            }
        }
        self::assertSame([true, true, true, true], $errors);
        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Delta'], Book::query()->orderBy('id')->pluck('title'));
        self::assertSame(4, Book::query()->count());
    }

    #[DataProvider('drivers')]
    public function testAKeyDeclaredOnAParentWithARestrictedSetterIsAssigned(string $driver): void
    {
        $this->start($driver);
        $author = new GuardedAuthor();
        $author->name = 'Hopper';
        $author->save();
        self::assertTrue($author->stored());
        self::assertGreaterThan(2, $author->id);
        self::assertSame('Hopper', GuardedAuthor::find($author->id)?->name);
        self::assertSame(3, GuardedAuthor::query()->count());
        self::assertSame('Ada', GuardedAuthor::find(1)?->refresh()->name);
    }

    #[DataProvider('drivers')]
    public function testFindOnAQueryWithAnOrFindsOnlyTheRowAsked(string $driver): void
    {
        $this->start($driver);
        $query = Book::query()->where('title', 'Alpha')->orWhere('title', 'Beta');
        self::assertSame('Beta', $query->find(2)?->title);
        self::assertNull($query->find(3));
        self::assertNull($query->find(999));
        $children = Category::find(1)->related('children')->where('name', 'child')->orWhere('name', 'other');
        self::assertNull($children->find(3));
        self::assertSame('child', $children->find(2)?->name);
    }

    #[DataProvider('drivers')]
    public function testAnOrAsTheFirstConditionOfARelatedQueryIsItsFirstCondition(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_categories')->insert(['id' => 4, 'parent_id' => 1, 'name' => 'sibling']);
        $this->db->table('tide_categories')->insert(['id' => 5, 'parent_id' => 2, 'name' => 'sibling']);
        $query = Category::find(1)->related('children')->orWhere('name', 'sibling');
        self::assertSame([4], $query->pluck('id'));
        self::assertSame(1, $query->update(['name' => 'renamed']));
        self::assertSame('child', $this->db->table('tide_categories')->where('id', 2)->value('name'));
        self::assertSame('sibling', $this->db->table('tide_categories')->where('id', 5)->value('name'));
        // A second alternative still stays inside the parent.
        $either = Category::find(1)->related('children')->orWhere('name', 'renamed')->orWhere('name', 'child');
        self::assertSame([2, 4], $either->orderBy('id')->pluck('id'));
    }

    #[DataProvider('drivers')]
    public function testAnIntKeyOfZeroIsNumberedByTheDatabase(string $driver): void
    {
        $this->start($driver);
        $author = new ZeroAuthor();
        $author->name = 'Zero';
        $author->save();
        self::assertGreaterThan(2, $author->id);
        $author->name = 'Renamed';
        $author->save();
        self::assertSame('Renamed', $this->db->table('tide_authors')->where('id', $author->id)->value('name'));
        self::assertSame('Renamed', $author->refresh()->name);
        self::assertFalse($this->db->table('tide_authors')->where('id', 0)->exists());
    }

    #[DataProvider('drivers')]
    public function testConditionsOnAManyToManyRelationMeanTheRelatedModelsColumns(string $driver): void
    {
        $this->start($driver);
        $this->db->execute('drop table if exists tide_taggings');
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        // A pivot with a key and a name of its own, as many have.
        $this->db->execute(
            "create table tide_taggings (id {$id}, book_id integer not null, tag_id integer not null, name varchar(20) not null)",
        );
        $this->db
            ->table('tide_taggings')
            ->insertMany([
                ['id' => 100, 'book_id' => 1, 'tag_id' => 1, 'name' => 'pivot'],
                ['id' => 101, 'book_id' => 1, 'tag_id' => 2, 'name' => 'pivot'],
            ]);
        try {
            $book = TaggedBook::find(1);
            $tags = $book->related('tags');
            self::assertSame(['SQL'], (clone $tags)->where('id', 2)->pluck('name'));
            self::assertSame(['PHP'], (clone $tags)->where(['name' => 'PHP'])->pluck('name'));
            self::assertSame(['SQL', 'PHP'], (clone $tags)->orderByDesc('id')->pluck('name'));
            self::assertSame(2, (clone $tags)->max('id'));
            self::assertSame(2, (clone $tags)->whereIn('id', [1, 2])->count());
            $loaded = TaggedBook::query()
                ->with('tags', static fn(ModelQuery $query): ModelQuery => $query->where('id', 2))
                ->find(1);
            self::assertSame(['SQL'], array_map(static fn(Model $tag): string => $tag->name, $loaded->tags));
            self::assertSame(
                1,
                TaggedBook::query()
                    ->whereHas('tags', static fn(ModelQuery $q) => $q->where('id', 2))
                    ->count(),
            );
        } finally {
            $this->db->execute('drop table if exists tide_taggings');
        }
    }

    #[DataProvider('drivers')]
    public function testAPlainModelAndAHookedOneSaveTheSame(string $driver): void
    {
        $this->start($driver);
        // Book has no hook and is read at once; GuardedAuthor is read property by property.
        $book = Book::find(1);
        $book->save();
        $book->title = 'Changed';
        $book->save();
        self::assertSame('Changed', $this->db->table('tide_books')->where('id', 1)->value('title'));
        self::assertSame(1, (int) $this->db->table('tide_books')->where('id', 1)->value('votes'));
    }
}

abstract class GuardedBase extends Model
{
    public private(set) int $id;
}

#[Table('tide_authors')]
final class GuardedAuthor extends GuardedBase
{
    public string $name;
}

#[Table('tide_authors')]
final class ZeroAuthor extends Model
{
    public int $id = 0;
    public string $name = '';
}

#[Table('tide_tags')]
final class TaggingTag extends Model
{
    public int $id;
    public string $name;
}

#[Table('tide_books')]
final class TaggedBook extends Model
{
    public int $id;
    public string $title;

    /** @var list<TaggingTag> */
    #[BelongsToMany(TaggingTag::class, pivot: 'tide_taggings', key: 'book_id', relatedKey: 'tag_id')]
    public array $tags;
}

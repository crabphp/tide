<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Database\ColumnName;
use Crab\Tide\BelongsTo;
use Crab\Tide\BelongsToMany;
use Crab\Tide\HasMany;
use Crab\Tide\HasOne;
use Crab\Tide\Model;
use Crab\Tide\ModelMap;
use Crab\Tide\ModelQuery;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RelationEdgeTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testExplicitOwnerAndLocalColumnsAreNotPropertyNames(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_authors')->where('id', 1)->update(['name' => 'Alpha']);
        $author = NamedAuthor::query()->with('books', 'firstBook')->find(1);
        self::assertSame([1], array_column($author->books, 'id'));
        self::assertSame(1, $author->firstBook->id);
        self::assertSame('Alpha', NamedBook::query()->with('author')->find(1)->author->label);
        self::assertSame(1, $author->related('books')->first()->id);
        self::assertSame(1, NamedAuthor::query()->whereHas('books')->first()->id);
        self::assertSame(1, NamedBook::query()->whereHas('author')->first()->id);
    }

    #[DataProvider('drivers')]
    public function testIntegerAndNumericStringKeysMatchAsPhpArrayKeys(string $driver): void
    {
        $this->start($driver);
        $authors = StringIdAuthor::query()->with('books')->orderBy('id')->get();
        self::assertSame('1', $authors[0]->id);
        self::assertSame([1, 2], array_column($authors[0]->books, 'id'));
        self::assertSame([3], array_column($authors[1]->books, 'id'));
    }

    #[DataProvider('drivers')]
    public function testManyToManySharesAcrossBatchesAndNestedLoadsDistinctObjectsOnce(string $driver): void
    {
        $this->start($driver);
        $this->db->transaction(static function (\Crab\Contracts\Database\Connection $tx): void {
            for ($id = 5; $id <= 1_002; ++$id) {
                $tx->table('tide_books')->insert(['id' => $id, 'title' => 'Book']);
                $tx->table('tide_book_tag')->insert(['book_id' => $id, 'tag_id' => 1]);
            }
        });
        $connection = $this->recording();
        $books = new ModelQuery(ModelMap::of(Book::class), $connection)
            ->with('tags.books')
            ->orderBy('id')
            ->get();
        self::assertCount(4, $connection->statements);
        self::assertSame($books[0]->tags[0], $books[1_001]->tags[0]);
        self::assertCount(1_000, $books[0]->tags[0]->books);
        self::assertCount(1_000, $connection->statements[1][1]);
        self::assertCount(2, $connection->statements[2][1]);
        self::assertCount(2, $connection->statements[3][1]);
    }

    #[DataProvider('drivers')]
    public function testMissingNullableRowsBecomeNullAndReloadReplacesAnAssignedRelation(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_books')->where('id', 1)->update(['author_id' => 999]);
        $book = Book::query()->with('author')->find(1);
        self::assertNull($book->author);
        $book->author = \Crab\Tide\Tests\Support\Author::find(1);
        $book->save();
        self::assertSame(999, Book::find(1)->author_id);
        Book::load([$book], 'author');
        self::assertNull($book->author);
    }

    #[DataProvider('drivers')]
    public function testAPivotAliasCannotOverwriteAModelColumn(string $driver): void
    {
        $this->start($driver);
        $this->db->execute("alter table tide_tags add column tide_pivot varchar(100) not null default 'tag-data'");
        $book = PivotBook::query()->with('tags')->find(1);
        self::assertSame('tag-data', $book->tags[0]->tide_pivot);
        self::assertSame([1, 2], array_column($book->tags, 'id'));
    }

    #[DataProvider('drivers')]
    public function testSelfTypesAndGeneratedAliasNamesRemainDistinct(string $driver): void
    {
        $this->start($driver);
        $this->db->execute('drop table if exists tide_related_0');
        $this->db->execute('create table tide_related_0 (id integer primary key, parent_id integer null)');
        try {
            $this->db
                ->table('tide_related_0')
                ->insertMany([['id' => 1, 'parent_id' => null], ['id' => 2, 'parent_id' => 1]]);
            self::assertSame(1, SelfNode::query()->with('parent')->find(2)->parent->id);
            self::assertSame([2], array_column(SelfNode::query()->whereHas('parent')->get(), 'id'));
            self::assertSame([1], array_column(SelfNode::query()->whereDoesntHave('parent')->get(), 'id'));
        } finally {
            $this->db->execute('drop table tide_related_0');
        }
    }

    public function testTheRelatedMapAndItsForeignColumnAreCheckedOnlyWhenLoading(): void
    {
        $this->start('sqlite');
        $map = ModelMap::of(BadRemoteColumn::class);
        self::assertSame('missing', $map->relations['books']->attribute->foreignKey);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs the column "missing"');
        BadRemoteColumn::query()->with('books')->get();
    }

    public function testAnExplicitLocalColumnAllowsLoadingFromAKeylessModel(): void
    {
        $this->start('sqlite');
        $this->db->table('tide_authors')->where('id', 1)->update(['name' => 'Alpha']);
        $author = KeylessAuthor::query()->with('books')->where('name', 'Alpha')->first();
        self::assertSame([1], array_column($author->books, 'id'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declare public int $id;');
        $author->related('books');
    }

    public function testADefaultLocalKeyIsRequiredOnFirstLoad(): void
    {
        $this->start('sqlite');
        self::assertNull(ModelMap::of(KeylessDefaultAuthor::class)->key);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declare public int $id;');
        KeylessDefaultAuthor::query()->with('books')->get();
    }
}

#[Table('tide_authors')]
final class NamedAuthor extends Model
{
    public int $id;
    #[ColumnName('name')]
    public string $label;
    #[HasMany(NamedBook::class, 'title', 'name')]
    public array $books;
    #[HasOne('title', 'name')]
    public ?NamedBook $firstBook;
}

#[Table('tide_books')]
final class NamedBook extends Model
{
    public int $id;
    #[ColumnName('title')]
    public string $label;
    #[BelongsTo('title', 'name')]
    public ?NamedAuthor $author;
}

#[Table('tide_authors')]
final class StringIdAuthor extends Model
{
    public string $id;
    public string $name;
    #[HasMany(Book::class, 'author_id')]
    public array $books;
}

#[Table('tide_authors')]
final class BadRemoteColumn extends Model
{
    public int $id;
    #[HasMany(Book::class, 'missing')]
    public array $books;
}

#[Table('tide_authors')]
final class KeylessAuthor extends Model
{
    public string $name;
    #[HasMany(NamedBook::class, 'title', 'name')]
    public array $books;
}

#[Table('tide_authors')]
final class KeylessDefaultAuthor extends Model
{
    public string $name;
    #[HasMany(Book::class, 'author_id')]
    public array $books;
}

#[Table('tide_books')]
final class PivotBook extends Model
{
    public int $id;
    #[BelongsToMany(PivotTag::class, 'tide_book_tag', 'book_id', 'tag_id')]
    public array $tags;
}

#[Table('tide_tags')]
final class PivotTag extends Model
{
    public int $id;
    public string $name;
    public string $tide_pivot;
}

#[Table('tide_related_0')]
final class SelfNode extends Model
{
    public int $id;
    public ?int $parent_id;
    #[BelongsTo('parent_id')]
    public ?self $parent;
}

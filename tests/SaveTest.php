<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Database\ColumnName;
use Crab\Tide\Model;
use Crab\Tide\RowGone;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Money;
use Crab\Tide\Tests\Support\Price;
use Crab\Tide\Tests\Support\RecordingDatabase;
use Crab\Tide\Tests\Support\Status;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

final class SaveTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testInsertGeneratedAndGivenKeysLeavesDatabaseDefaultsUnread(string $driver): void
    {
        $this->start($driver);
        $book = new Book('Inserted', 1);
        self::assertFalse($book->stored());
        self::assertSame($book, $book->save());
        self::assertSame(5, $book->id);
        self::assertTrue($book->stored());
        self::assertFalse(isset($book->status));
        self::assertSame($book, $book->refresh());
        self::assertSame(Status::Draft, $book->status);
        self::assertSame([], $book->meta);
        self::assertSame(0, $book->votes);
        $given = new Book('Given');
        $given->id = 100;
        $given->save();
        self::assertSame(100, Book::find(100)->id);
    }

    #[DataProvider('drivers')]
    public function testOnlyChangedColumnsAreWrittenAndNoChangeMakesNoStatement(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        $book->save();
        self::assertSame([], $recording->recording->statements);
        $book->title = 'Changed';
        $book->save();
        self::assertSame(
            [['update `tide_books` set `title` = ? where `id` = ?', ['Changed', 1]]],
            $recording->recording->statements,
        );
        $book->save();
        self::assertCount(1, $recording->recording->statements);
        // Each object changes its own columns; neither overwrites the other's.
        Tide::use($this->db);
        $other = Book::find(1);
        $book->votes = 10;
        $book->save();
        $other->title = 'Other';
        $other->save();
        self::assertSame(10, Book::find(1)->votes);
        self::assertSame('Other', Book::find(1)->title);
    }

    #[DataProvider('drivers')]
    public function testArraysEnumsDatesAndCastObjectsUseTheirSpecifiedEquality(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(1);
        $book->meta = ['a' => [1]];
        $book->status = Status::Published;
        $book->publishedAt = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $book->save();
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        $book->publishedAt = new \DateTimeImmutable('2026-01-02T04:04:05+01:00');
        $book->save();
        self::assertSame([], $recording->recording->statements);
        $book->meta['a'][] = 2;
        $book->save();
        self::assertStringContainsString('set `meta` = ?', $recording->recording->statements[0][0]);
        $price = new Price();
        $price->amount = new Money(10);
        $price->save();
        $price->amount->cents = 20;
        $price->save();
        self::assertSame(20, Price::find($price->id)->amount->cents);
        $count = count($recording->recording->statements);
        $price->amount = new Money(20);
        $price->save();
        self::assertCount($count, $recording->recording->statements);
    }

    #[DataProvider('drivers')]
    public function testFailedInsertAndUpdateCanBeRetriedWithoutChangingStoredState(string $driver): void
    {
        $this->start($driver);
        $new = new Book('Conflict');
        $new->id = 1;
        try {
            $new->save();
        } catch (\RuntimeException $error) {
            $insertFailed = $error;
        }
        self::assertNotNull($insertFailed);
        self::assertFalse($new->stored());
        self::assertSame(1, $new->id);
        $new->id = 20;
        $new->save();
        self::assertTrue($new->stored());
        $book = Book::query()->with('author')->find(2);
        $author = $book->author;
        $book->id = 1;
        $book->author_id = 2;
        try {
            $book->save();
        } catch (\RuntimeException $error) {
            $updateFailed = $error;
        }
        self::assertNotNull($updateFailed);
        self::assertTrue($book->stored());
        self::assertSame($author, $book->author);
        $book->id = 21;
        $book->save();
        self::assertNull(Book::find(2));
        self::assertSame(2, Book::find(21)->author_id);
        self::assertFalse(isset($book->author));
    }

    #[DataProvider('drivers')]
    public function testDeleteMakesNewAndSaveReinsertsWithoutDroppingRelations(string $driver): void
    {
        $this->start($driver);
        $book = Book::query()->with('author')->find(1);
        $author = $book->author;
        $book->id = 90;
        $book->delete();
        self::assertFalse($book->stored());
        self::assertNull(Book::find(1));
        self::assertSame($author, $book->author);
        $book->save();
        self::assertSame(90, Book::find(90)->id);
        self::assertTrue($book->stored());
    }

    #[DataProvider('drivers')]
    public function testRefreshReusesTheObjectResetsColumnsAndUnloadsRelations(string $driver): void
    {
        $this->start($driver);
        $book = Book::query()->with('author', 'chapters')->find(1);
        $book->id = 90;
        $book->title = 'Unsaved';
        Book::query()->where('id', 1)->update(['title' => 'Database']);
        self::assertSame($book, $book->refresh());
        self::assertSame(1, $book->id);
        self::assertSame('Database', $book->title);
        self::assertFalse(isset($book->author));
        self::assertFalse(isset($book->chapters));
        Book::query()->where('id', 1)->delete();
        $this->expectException(RowGone::class);
        $this->expectExceptionMessage('discard this model');
        $book->refresh();
    }

    #[DataProvider('drivers')]
    public function testCloneKeepsTheStoredKeyAndZeroAffectedRowsAreNotAnError(string $driver): void
    {
        $this->start($driver);
        $original = Book::find(1);
        $copy = clone $original;
        $copy->title = 'Copy';
        $copy->save();
        self::assertSame('Alpha', $original->title);
        self::assertSame('Copy', Book::find(1)->title);
        $original->delete();
        $copy->title = 'Gone';
        $copy->save();
        self::assertTrue($copy->stored());
        self::assertNull(Book::find(1));
    }

    #[DataProvider('drivers')]
    public function testStringKeysMustBeGivenAndMappedKeysAreUsed(string $driver): void
    {
        $this->start($driver);
        $this->db->execute('drop table if exists tide_strings');
        $this->db->execute('create table tide_strings (uuid varchar(100) primary key, name varchar(100) not null)');
        $item = new StringKey();
        $item->name = 'Item';
        try {
            $item->save();
        } catch (\LogicException $error) {
            $message = $error->getMessage();
        }
        self::assertStringContainsString('give it a value before save()', $message);
        self::assertSame(0, $this->db->table('tide_strings')->count());
        $item->key = 'a';
        $item->save();
        self::assertSame('Item', StringKey::find('a')->name);
        $item->key = 'b';
        $item->save();
        self::assertNull(StringKey::find('a'));
        self::assertSame('b', StringKey::find('b')->key);
        $keys = [];
        StringKey::query()->chunkById(2, static function (array $models) use (&$keys): void {
            $keys = array_column($models, 'key');
        });
        self::assertSame(['b'], $keys);
        $this->db->execute('drop table tide_strings');
    }

    #[DataProvider('invalidWrites')]
    public function testInvalidOperationsFailBeforeAStatement(string $operation, string $message): void
    {
        $this->start('sqlite');
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        try {
            match ($operation) {
                'empty' => new EmptyModel()->save(),
                'delete' => new Book()->delete(),
                'refresh' => new Book()->refresh(),
                'keyless' => new SaveKeyless()->save(),
            };
        } catch (\LogicException $error) {
            $actual = $error->getMessage();
        }
        self::assertStringContainsString($message, $actual);
        self::assertSame([], $recording->recording->statements);
    }

    #[DataProvider('drivers')]
    public function testBackedGettersNeverTransformStoredValuesAndRefreshBypassesSetters(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_authors')->where('id', 1)->update(['name' => 'mixed']);
        $author = GetterAuthor::find(1);
        self::assertSame('MIXED', $author->name);
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        $author->save();
        self::assertSame([], $recording->recording->statements);
        $author->name = ' assigned ';
        self::assertSame('ASSIGNED', $author->name);
        $author->save();
        self::assertSame('assigned', $this->db->table('tide_authors')->where('id', 1)->value('name'));
        $this->db->table('tide_authors')->where('id', 1)->update(['name' => ' raw ']);
        $author->refresh();
        self::assertSame(' RAW ', $author->name);
    }

    #[DataProvider('drivers')]
    public function testNewlyAssignedDefaultsAndLoadedCastMutationsAreWritten(string $driver): void
    {
        $this->start($driver);
        $book = new Book('Default');
        $book->save();
        $book->votes = 7;
        $book->save();
        self::assertSame(7, Book::find($book->id)->votes);
        $this->db->table('tide_prices')->insert(['id' => 1, 'amount' => 10]);
        $price = Price::find(1);
        $price->amount->cents = 30;
        $price->save();
        self::assertSame(30, Price::find(1)->amount->cents);
    }

    #[DataProvider('drivers')]
    public function testGettersSeeGeneratedKeysAndRefreshDoesNotApplyGettersTwice(string $driver): void
    {
        $this->start($driver);
        $author = new LabelledAuthor();
        $author->name = 'New';
        $author->save();
        self::assertSame('New #3', $author->name);
        $recording = new RecordingDatabase($this->db);
        Tide::use($recording);
        $author->save();
        self::assertSame([], $recording->recording->statements);
        $this->db->table('tide_authors')->where('id', 3)->update(['name' => ' raw ']);
        $author->refresh();
        self::assertSame(' raw  #3', $author->name);
        $recording->recording->statements = [];
        $author->save();
        self::assertSame([], $recording->recording->statements);
    }

    public static function invalidWrites(): array
    {
        return [
            ['empty',   'give at least one column a value'],
            ['delete',  'save it before deleting'],
            ['refresh', 'save it before refreshing'],
            ['keyless', 'declare public int $id;'],
        ];
    }
}

#[Table('tide_strings', key: 'uuid')]
final class StringKey extends Model
{
    #[ColumnName('uuid')]
    public string $key;
    public string $name;
}

#[Table('tide_books')]
final class EmptyModel extends Model
{
    public int $id;
    public string $title;
}

#[Table('tide_books')]
final class SaveKeyless extends Model
{
    public string $title = 'Title';
}

#[Table('tide_authors')]
final class GetterAuthor extends Model
{
    public int $id;
    public string $name {
        get => strtoupper($this->name);
        set => trim($value);
    }
}

#[Table('tide_authors')]
final class LabelledAuthor extends Model
{
    public int $id;
    public string $name {
        get => $this->name . (isset($this->id) ? ' #' . $this->id : '');
        set => trim($value);
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Contracts\Database\Connection;
use Crab\Tide\Tests\Support\Author;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SaveManyTest extends DatabaseTestCase
{
    private static function author(string $name): Author
    {
        $author = new Author();
        $author->name = $name;
        return $author;
    }

    #[DataProvider('drivers')]
    public function testNewAndStoredModelsAreSavedAndReturnedWithTheirKeys(string $driver): void
    {
        $this->start($driver);
        $existing = Author::find(1);
        $existing->name = 'Ada Lovelace';
        $new = [self::author('Hopper'), self::author('Liskov')];
        $saved = Author::saveMany([$existing, ...$new]);
        self::assertSame([$existing, ...$new], $saved);
        self::assertTrue($new[0]->stored() && $new[1]->stored());
        self::assertSame(['Hopper', 'Liskov'], [Author::find($new[0]->id)?->name, Author::find($new[1]->id)?->name]);
        self::assertSame('Ada Lovelace', $this->db->table('tide_authors')->where('id', 1)->value('name'));
        self::assertSame([], Author::saveMany([]));
    }

    #[DataProvider('drivers')]
    public function testAllAreWrittenOrNone(string $driver): void
    {
        $this->start($driver);
        $first = self::author('Saved first');
        try {
            Author::saveMany([$first, new BrokenAuthor()]);
            self::fail('The second model should have failed.');
        } catch (\Throwable) {
            // Checked below.
        }
        self::assertSame(0, $this->db->table('tide_authors')->where('name', 'Saved first')->count());
        // The rollback made the first new again, so saving it again writes it.
        self::assertFalse($first->stored());
        $first->save();
        self::assertSame(1, $this->db->table('tide_authors')->where('name', 'Saved first')->count());
    }

    #[DataProvider('drivers')]
    public function testInsideATransactionItIsASavepointOfIt(string $driver): void
    {
        $this->start($driver);
        $this->db->transaction(static function (Connection $tx): void {
            Author::saveMany([self::author('Kept')]);
            try {
                Author::saveMany([self::author('Undone'), new BrokenAuthor()]);
            } catch (\Throwable) {
                // Only the inner saveMany is undone.
            }
        });
        self::assertSame(1, $this->db->table('tide_authors')->where('name', 'Kept')->count());
        self::assertSame(0, $this->db->table('tide_authors')->where('name', 'Undone')->count());
    }

    public function testModelsOfAnotherClassAreRefusedBeforeAnything(): void
    {
        $this->start('sqlite');
        try {
            Author::saveMany([self::author('Fine'), new Book('Wrong')]);
            self::fail('A Book is not an Author.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('takes models of that class', $error->getMessage());
        }
        self::assertSame(0, $this->db->table('tide_authors')->where('name', 'Fine')->count());
    }
}

/**
 * An author whose save fails at the database: its table has no such column.
 */
#[\Crab\Tide\Table('tide_authors')]
final class BrokenAuthor extends \Crab\Tide\Model
{
    public int $id;
    public string $name = 'Broken';
    public string $no_such_column = 'x';
}

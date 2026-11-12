<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Database\Json;
use Crab\Testing\FrozenClock;
use Crab\Tide\Model;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Status;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

final class InsertManyTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testModelsAreWrittenAsSaveWritesThemAndCounted(string $driver): void
    {
        $this->tables($driver);
        $lines = [];
        foreach ([
            [1, 2, Status::Draft],
            [2, 1, Status::Published],
            [3, 5, Status::Draft],
        ] as [$product, $quantity, $status]) {
            $line = new BulkLine();
            $line->product_id = $product;
            $line->quantity = $quantity;
            $line->status = $status;
            $line->meta = ['gift' => $product === 2];
            $lines[] = $line;
        }
        self::assertSame(3, BulkLine::insertMany($lines));
        $rows = $this->db->table('tide_bulk_lines')->orderBy('product_id')->get();
        self::assertSame([1, 2, 3], array_map(static fn(array $row): int => (int) $row['product_id'], $rows));
        self::assertSame(['draft', 'published', 'draft'], array_column($rows, 'status'));
        self::assertSame(['gift' => true], json_decode((string) $rows[1]['meta'], true));
        self::assertSame(['2026-04-05 06:07:08'], array_values(array_unique(array_column($rows, 'created_at'))));
        self::assertSame(['2026-04-05 06:07:08'], array_values(array_unique(array_column($rows, 'updated_at'))));
        // The database numbered them, and said only the first: they stay new, without a key.
        foreach ($lines as $line) {
            self::assertFalse($line->stored());
            self::assertFalse(isset($line->id));
        }
    }

    #[DataProvider('drivers')]
    public function testANullLeftToTheDatabaseIsAColumnLeftOutOfItsRowsOnly(string $driver): void
    {
        $this->tables($driver);
        $plain = new BulkLine();
        $plain->product_id = 1;
        $plain->quantity = 1;
        $noted = new BulkLine();
        $noted->product_id = 2;
        $noted->quantity = 1;
        $noted->note = 'wrapped';
        self::assertSame(2, BulkLine::insertMany([$plain, $noted]));
        self::assertSame(
            ['by default', 'wrapped'],
            $this->db->table('tide_bulk_lines')->orderBy('product_id')->pluck('note'),
        );
    }

    #[DataProvider('drivers')]
    public function testAModelWithAGivenKeyIsStoredAfterwardsAndUndoneWithItsTransaction(string $driver): void
    {
        $this->tables($driver);
        $codes = [];
        foreach (['a', 'b'] as $code) {
            $made = new BulkCode();
            $made->code = $code;
            $made->label = strtoupper($code);
            $codes[] = $made;
        }
        self::assertSame(2, BulkCode::insertMany($codes));
        self::assertTrue($codes[0]->stored());
        self::assertSame('2026-04-05 06:07:08', $codes[0]->created_at?->format('Y-m-d H:i:s'));
        $codes[1]->label = 'Bee';
        $codes[1]->save();
        self::assertSame('Bee', $this->db->table('tide_bulk_codes')->where('code', 'b')->value('label'));

        $undone = new BulkCode();
        $undone->code = 'c';
        $undone->label = 'C';
        try {
            $this->db->transaction(static function () use ($undone): never {
                BulkCode::insertMany([$undone]);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
            // Checked below.
        }
        self::assertFalse($undone->stored());
        self::assertNull($undone->created_at);
        self::assertSame(1, BulkCode::insertMany([$undone]));
        self::assertSame(3, $this->db->table('tide_bulk_codes')->count());
    }

    #[DataProvider('drivers')]
    public function testManyRowsGoInParts(string $driver): void
    {
        $this->tables($driver);
        $lines = [];
        for ($i = 1; $i <= 1201; $i++) {
            $line = new BulkLine();
            $line->product_id = $i;
            $line->quantity = 1;
            $lines[] = $line;
        }
        self::assertSame(1201, BulkLine::insertMany($lines));
        self::assertSame(1201, $this->db->table('tide_bulk_lines')->count());
        self::assertSame(0, BulkLine::insertMany([]));
    }

    public function testWhatCannotBeInsertedTogetherIsRefusedBeforeAnything(): void
    {
        $this->tables('sqlite');
        $line = new BulkLine();
        $line->product_id = 1;
        $line->quantity = 1;
        $stored = new BulkLine();
        $stored->product_id = 2;
        $stored->quantity = 1;
        $stored->save();
        foreach ([
            [[$line, $line], 'give each once'],
            [[$line, $stored], 'takes new models'],
            [[$line, new BulkCode()], 'takes models of that class'],
        ] as [$models, $message]) {
            try {
                BulkLine::insertMany($models);
                self::fail('Expected a refusal: ' . $message);
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(1, $this->db->table('tide_bulk_lines')->count());
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('drop table if exists tide_bulk_lines');
            $this->db->execute('drop table if exists tide_bulk_codes');
        }
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testAFailureInALaterPartWritesNothingAndLeavesEveryModelNew(string $driver): void
    {
        $this->tables($driver);
        $codes = [];
        foreach (range(1, 501) as $n) {
            $made = new BulkCode();
            // The 501st repeats the first, so the second statement of the batch fails.
            $made->code = 'c' . ($n === 501 ? 1 : $n);
            $made->label = 'L' . $n;
            $codes[] = $made;
        }
        try {
            BulkCode::insertMany($codes);
            self::fail('A duplicate key was inserted.');
        } catch (\Crab\Database\DatabaseException) {
            // The duplicate is the database's to refuse.
        }
        self::assertSame(0, $this->db->table('tide_bulk_codes')->count(), 'The first 500 rows are not left behind.');
        self::assertFalse($codes[0]->stored());

        array_pop($codes);
        self::assertSame(500, BulkCode::insertMany($codes), 'The same models can be inserted again.');
        self::assertTrue($codes[0]->stored());
    }

    private function tables(string $driver): void
    {
        $this->db = $this->database($driver);
        Tide::use($this->db, new FrozenClock('2026-04-05 06:07:08'));
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        $this->db->execute('drop table if exists tide_bulk_lines');
        $this->db->execute('drop table if exists tide_bulk_codes');
        $this->db->execute(
            "create table tide_bulk_lines (id {$id}, product_id integer not null, quantity integer not null, status varchar(20) not null default 'draft', meta varchar(200) not null default '{}', note varchar(50) null default 'by default', created_at datetime null, updated_at datetime null)",
        );
        $this->db->execute(
            'create table tide_bulk_codes (code varchar(10) primary key, label varchar(20) not null, created_at datetime null, updated_at datetime null)',
        );
    }
}

#[Table('tide_bulk_lines')]
final class BulkLine extends Model
{
    public int $id;
    public int $product_id;
    public int $quantity;
    public Status $status = Status::Draft;
    #[Json]
    public array $meta = [];
    // No default: never given, it leaves the column to the database's.
    public ?string $note;
    public ?\DateTimeImmutable $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;
}

#[Table('tide_bulk_codes', key: 'code')]
final class BulkCode extends Model
{
    public string $code;
    public string $label;
    public ?\DateTimeImmutable $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;
}

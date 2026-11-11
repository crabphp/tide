<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Testing\FrozenClock;
use Crab\Tide\Model;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tide;
use Crab\Tide\Timestamps;
use PHPUnit\Framework\Attributes\DataProvider;

final class TimestampsTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    #[DataProvider('drivers')]
    public function testASaveStampsANewModelAndEachChange(string $driver): void
    {
        $this->stamped($driver);
        $row = new Stamped('first');
        $row->save();
        self::assertSame('2026-03-04 05:06:07', $row->created_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-04 05:06:07', $row->updated_at?->format('Y-m-d H:i:s'));
        self::assertSame(['2026-03-04 05:06:07', '2026-03-04 05:06:07'], $this->times($row->id));

        // Nothing changed: no statement, and the time stays.
        $this->clock->set('2026-03-05 00:00:00');
        $row->save();
        self::assertSame('2026-03-04 05:06:07', $this->times($row->id)[1]);

        $row->name = 'second';
        $row->save();
        self::assertSame(['2026-03-04 05:06:07', '2026-03-05 00:00:00'], $this->times($row->id));
        self::assertSame('2026-03-05 00:00:00', $row->updated_at?->format('Y-m-d H:i:s'));

        // Saved again with nothing new, the time just written is not a change.
        $this->clock->set('2026-03-06 00:00:00');
        $row->save();
        self::assertSame('2026-03-05 00:00:00', $this->times($row->id)[1]);

        // Read back, the model is unchanged and writes nothing.
        $read = Stamped::find($row->id);
        $read->save();
        self::assertSame('2026-03-05 00:00:00', $this->times($row->id)[1]);
    }

    #[DataProvider('drivers')]
    public function testATimeTheApplicationGivesIsKept(string $driver): void
    {
        $this->stamped($driver);
        $row = new Stamped('given');
        $row->created_at = new \DateTimeImmutable('2020-01-01 00:00:00');
        $row->save();
        self::assertSame(['2020-01-01 00:00:00', '2026-03-04 05:06:07'], $this->times($row->id));

        $row->name = 'again';
        $row->updated_at = new \DateTimeImmutable('2021-02-02 02:02:02');
        $row->save();
        self::assertSame(['2020-01-01 00:00:00', '2021-02-02 02:02:02'], $this->times($row->id));
    }

    #[DataProvider('drivers')]
    public function testBulkChangesAreStamped(string $driver): void
    {
        $this->stamped($driver);
        $row = new Stamped('bulk')->save();
        $this->clock->set('2026-04-01 00:00:00');
        Stamped::query()->where('id', $row->id)->update(['name' => 'changed']);
        self::assertSame('2026-04-01 00:00:00', $this->times($row->id)[1]);

        $this->clock->set('2026-04-02 00:00:00');
        Stamped::query()->where('id', $row->id)->increment('hits');
        self::assertSame('2026-04-02 00:00:00', $this->times($row->id)[1]);
        Stamped::query()->where('id', $row->id)->decrement('hits', 1, ['name' => 'down']);
        self::assertSame(['down', 0], [
            $this->db->table('tide_stamped')->where('id', $row->id)->value('name'),
            (int) $this->db->table('tide_stamped')->where('id', $row->id)->value('hits'),
        ]);

        Stamped::query()
            ->where('id', $row->id)
            ->update(['name' => 'given', 'updated_at' => new \DateTimeImmutable('2019-09-09 09:09:09')]);
        self::assertSame('2019-09-09 09:09:09', $this->times($row->id)[1]);
        self::assertSame('2026-03-04 05:06:07', $this->times($row->id)[0]);
    }

    #[DataProvider('drivers')]
    public function testOneTimeCanBeLeftOutAndAParentCanDeclareThem(string $driver): void
    {
        $this->stamped($driver);
        $row = new CreatedOnly();
        $row->name = 'created only';
        $row->save();
        self::assertSame(['2026-03-04 05:06:07', null], $this->times($row->id));
        $this->clock->set('2026-05-05 00:00:00');
        $row->name = 'changed';
        $row->save();
        self::assertSame(['2026-03-04 05:06:07', null], $this->times($row->id));

        $child = new StampedChild();
        $child->name = 'from a parent';
        $child->save();
        self::assertSame(['2026-05-05 00:00:00', '2026-05-05 00:00:00'], $this->times($child->id));
    }

    #[DataProvider('drivers')]
    public function testAFailedInsertLeavesTheModelUnstamped(string $driver): void
    {
        $this->stamped($driver);
        new Stamped('taken')->save();
        $twin = new Stamped('taken');
        try {
            $twin->save();
            self::fail('The unique name should have been refused.');
        } catch (\Throwable) {
        }
        self::assertNull($twin->created_at);
        self::assertNull($twin->updated_at);
        self::assertFalse($twin->stored());
        $twin->name = 'free';
        $twin->save();
        self::assertTrue($twin->stored());
        self::assertNotNull($twin->created_at);
    }

    public function testTimestampsNeedDateColumns(): void
    {
        try {
            WrongStamp::query();
            self::fail('A text column cannot be a timestamp.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('#[Timestamps]', $error->getMessage());
            self::assertStringContainsString('created_at', $error->getMessage());
            self::assertStringContainsString('DateTimeImmutable', $error->getMessage());
        }
        try {
            MissingStamp::query();
            self::fail('A missing column cannot be a timestamp.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('updated: null', $error->getMessage());
        }
    }

    #[DataProvider('drivers')]
    public function testANullValueIsLeftToTheDatabaseOnInsert(string $driver): void
    {
        $this->stamped($driver);
        $row = new Defaults();
        $row->name = 'plain';
        $row->save();
        // The property was null, so the column took its default; the model learns it when asked.
        self::assertNull($row->note);
        self::assertSame(
            'filled by the database',
            $this->db->table('tide_stamped')->where('id', $row->id)->value('note'),
        );
        self::assertNull($this->db->table('tide_stamped')->where('id', $row->id)->value('created_at'));
        self::assertSame('filled by the database', $row->refresh()->note);

        $given = new Defaults();
        $given->name = 'given';
        $given->note = 'mine';
        $given->save();
        self::assertSame('mine', $this->db->table('tide_stamped')->where('id', $given->id)->value('note'));

        // On a stored model, null is a value like any other and is written.
        $given->note = null;
        $given->save();
        self::assertNull($this->db->table('tide_stamped')->where('id', $given->id)->value('note'));

        $empty = new Defaults();
        try {
            $empty->save();
            self::fail('A model with nothing to write should be refused.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('no value to write', $error->getMessage());
        }
    }

    public function testTheSystemClockIsUsedWithoutOne(): void
    {
        $this->stamped('sqlite');
        Tide::use($this->db);
        $before = time();
        $row = new Stamped('system')->save();
        self::assertGreaterThanOrEqual($before, $row->created_at?->getTimestamp());
        self::assertLessThanOrEqual(time(), $row->created_at?->getTimestamp());
        self::assertSame('000000', $row->created_at?->format('u'));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('drop table if exists tide_stamped');
        }
        parent::tearDown();
    }

    private function stamped(string $driver): void
    {
        $this->db = $this->database($driver);
        $this->clock = new FrozenClock('2026-03-04 05:06:07');
        Tide::use($this->db, $this->clock);
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        $this->db->execute('drop table if exists tide_stamped');
        $this->db->execute(
            "create table tide_stamped (id {$id}, name varchar(100) not null unique, hits integer not null default 0, note varchar(100) null default 'filled by the database', created_at datetime null, updated_at datetime null)",
        );
    }

    /**
     * @return array{?string, ?string}
     */
    private function times(int $id): array
    {
        $row = $this->db->table('tide_stamped')->where('id', $id)->first();
        return [$row['created_at'], $row['updated_at']];
    }
}

// No #[Timestamps]: a model keeps the times it declares.
#[Table('tide_stamped')]
final class Stamped extends Model
{
    public int $id;
    public int $hits = 0;
    public ?string $note = null;
    public ?\DateTimeImmutable $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;

    public function __construct(
        public string $name,
    ) {}
}

#[Table('tide_stamped'), Timestamps(updated: null)]
final class CreatedOnly extends Model
{
    public int $id;
    public string $name;
    public ?\DateTimeImmutable $created_at = null;
}

#[Timestamps]
abstract class StampedParent extends Model
{
    public int $id;
    public ?\DateTimeImmutable $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;
}

#[Table('tide_stamped')]
final class StampedChild extends StampedParent
{
    public string $name;
}

#[Table('tide_stamped'), Timestamps(created: null, updated: null)]
final class Defaults extends Model
{
    public int $id;
    public string $name;
    public ?string $note = null;
    public ?\DateTimeImmutable $created_at = null;
}

#[Table('tide_stamped'), Timestamps]
final class WrongStamp extends Model
{
    public int $id;
    public ?string $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;
}

#[Table('tide_stamped'), Timestamps]
final class MissingStamp extends Model
{
    public int $id;
    public ?\DateTimeImmutable $created_at = null;
}

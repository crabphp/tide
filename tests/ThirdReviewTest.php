<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Testing\FrozenClock;
use Crab\Tide\BelongsToMany;
use Crab\Tide\Model;
use Crab\Tide\ModelQuery;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\Category;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the third review found, in the forms an application writes.
 */
final class ThirdReviewTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testOptionalFiltersThatAddNothingLeaveTheFirstAlternativeTheFirstCondition(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_categories')->insert(['id' => 4, 'parent_id' => 1, 'name' => 'sibling']);
        $this->db->table('tide_categories')->insert(['id' => 5, 'parent_id' => 2, 'name' => 'sibling']);
        $filters = [];
        foreach ([
            static fn(ModelQuery $query): ModelQuery => $query->where($filters),
            static fn(ModelQuery $query): ModelQuery => $query->where(static fn(ModelQuery $none): ModelQuery => $none),
            static fn(ModelQuery $query): ModelQuery => $query->when(false, static fn(ModelQuery $q): ModelQuery => $q->where(
                'id',
                2,
            )),
        ] as $optional) {
            $query = $optional(Category::find(1)->related('children'))->orWhere('name', 'sibling');
            self::assertSame([4], $query->pluck('id'));
        }
        $query = Category::find(1)->related('children')->where($filters)->orWhere('name', 'sibling');
        self::assertSame(1, $query->update(['name' => 'renamed']));
        self::assertSame('child', $this->db->table('tide_categories')->where('id', 2)->value('name'));
        self::assertSame('sibling', $this->db->table('tide_categories')->where('id', 5)->value('name'));
    }

    #[DataProvider('drivers')]
    public function testAnEmptyAlternativeAfterARealFilterAddsNothing(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_categories')->insert(['id' => 4, 'parent_id' => 1, 'name' => 'sibling']);
        $this->db->table('tide_categories')->insert(['id' => 5, 'parent_id' => 2, 'name' => 'sibling']);
        foreach ([
            static fn(ModelQuery $query): ModelQuery => $query->orWhere([]),
            static fn(ModelQuery $query): ModelQuery => $query->orWhere(static fn(ModelQuery $group): ModelQuery => $group->when(false, static fn(ModelQuery $q): ModelQuery => $q->where(
                'id',
                2,
            ))),
        ] as $optional) {
            $query = $optional(Category::find(1)->related('children')->where('name', 'sibling'));
            self::assertSame([4], $query->pluck('id'));
        }
        $query = Category::find(1)->related('children')->where('name', 'sibling')->orWhere([]);
        self::assertSame(1, $query->update(['name' => 'renamed']));
        self::assertSame('child', $this->db->table('tide_categories')->where('id', 2)->value('name'));
        // A real alternative still counts, and still stays inside the parent.
        $either = Category::find(1)->related('children')->where('name', 'renamed')->orWhere('name', 'child');
        self::assertSame([2, 4], $either->orderBy('id')->pluck('id'));
    }

    #[DataProvider('drivers')]
    public function testColumnsGivenToReadsAndChunksOfAManyToManyRelationAreTheRelatedModels(string $driver): void
    {
        $this->start($driver);
        $this->taggings($driver);
        try {
            $tags = ThirdBook::find(1)->related('tags');
            self::assertSame([1 => 'PHP', 2 => 'SQL'], (clone $tags)->orderBy('id')->pluck('name', 'id'));
            self::assertSame(2, (clone $tags)->count('id'));
            $seen = [];
            (clone $tags)->chunkById(1, static function (array $models) use (&$seen): void {
                foreach ($models as $model) {
                    $seen[] = $model->id;
                }
            });
            self::assertSame([1, 2], $seen);
            self::assertSame(2, (clone $tags)->whereColumn('id', 'id')->count());
        } finally {
            $this->db->execute('drop table if exists tide_taggings');
        }
    }

    #[DataProvider('drivers')]
    public function testAConditionInSqlCanBeWrittenInsideARelationConstraint(string $driver): void
    {
        $this->start($driver);
        $long = static fn(ModelQuery $query): ModelQuery => $query->whereRaw('length(title) > ?', [5]);
        $book = Book::query()->with('chapters', $long)->find(1);
        self::assertSame(['Second'], array_map(static fn(Model $chapter): string => $chapter->title, $book->chapters));
        self::assertSame([1], Book::query()->whereHas('chapters', $long)->pluck('id'));
        self::assertSame([1, 2], Book::query()->whereRaw('votes < ?', [3])->orderBy('id')->pluck('id'));
        try {
            Book::query()->whereHas('chapters', static fn(ModelQuery $query): ModelQuery => $query->tap(
                static fn() => null,
            ));
            self::fail('tap() inside a closure should be refused.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('whereRaw()', $error->getMessage());
        }
    }

    #[DataProvider('drivers')]
    public function testARetryAfterARollbackKeepsTheTimeOfTheAttemptThatCommits(string $driver): void
    {
        $this->start($driver);
        $clock = new FrozenClock('2026-03-01 10:00:00');
        Tide::use($this->db, $clock);
        $this->db->execute('drop table if exists tide_stamped_retry');
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        $this->db->execute(
            "create table tide_stamped_retry (id {$id}, name varchar(50) not null, created_at datetime null, updated_at datetime null)",
        );
        try {
            $this->db
                ->table('tide_stamped_retry')
                ->insert([
                    'id' => 1,
                    'name' => 'old',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ]);
            $new = new RetryStamped();
            $new->name = 'new';
            $old = RetryStamped::find(1);
            $old->name = 'changed';
            $attempts = 0;
            while (true) {
                try {
                    $this->db->transaction(static function () use ($new, $old, $clock, &$attempts): void {
                        $new->save();
                        $old->save();
                        if (++$attempts === 1) {
                            $clock->set('2026-03-01 10:00:05');
                            throw new \RuntimeException('deadlock');
                        }
                    });
                    break;
                } catch (\RuntimeException) {
                    // Run again, as after a deadlock.
                }
            }
            $rows = $this->db->table('tide_stamped_retry')->orderBy('id')->get();
            self::assertSame(['2026-01-01 00:00:00', '2026-03-01 10:00:05'], [
                $rows[0]['created_at'],
                $rows[0]['updated_at'],
            ]);
            self::assertSame(['2026-03-01 10:00:05', '2026-03-01 10:00:05'], [
                $rows[1]['created_at'],
                $rows[1]['updated_at'],
            ]);
            self::assertSame('2026-03-01 10:00:05', $old->updated_at?->format('Y-m-d H:i:s'));
        } finally {
            $this->db->execute('drop table if exists tide_stamped_retry');
        }
    }

    public function testATimeIsKeptInTheZoneItIsReadBackIn(): void
    {
        $this->start('sqlite');
        $zone = date_default_timezone_get();
        date_default_timezone_set('Asia/Ho_Chi_Minh');
        try {
            Tide::use(
                $this->db,
                new FrozenClock(new \DateTimeImmutable('2026-02-01 12:00:00', new \DateTimeZone('UTC'))),
            );
            $this->db->execute('drop table if exists tide_stamped_zone');
            $this->db->execute(
                'create table tide_stamped_zone (id integer primary key autoincrement, name varchar(50) not null, created_at datetime null, updated_at datetime null)',
            );
            $saved = new ZoneStamped();
            $saved->name = 'zoned';
            $saved->save();
            $read = ZoneStamped::find($saved->id);
            self::assertSame($read->created_at?->format('Y-m-d H:i:s P'), $saved->created_at?->format('Y-m-d H:i:s P'));
            self::assertSame('2026-02-01 19:00:00 +07:00', $saved->created_at?->format('Y-m-d H:i:s P'));
        } finally {
            date_default_timezone_set($zone);
            $this->db->execute('drop table if exists tide_stamped_zone');
        }
    }

    private function taggings(string $driver): void
    {
        $this->db->execute('drop table if exists tide_taggings');
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        $this->db->execute(
            "create table tide_taggings (id {$id}, book_id integer not null, tag_id integer not null, name varchar(20) not null)",
        );
        $this->db
            ->table('tide_taggings')
            ->insertMany([
                ['id' => 100, 'book_id' => 1, 'tag_id' => 1, 'name' => 'pivot'],
                ['id' => 101, 'book_id' => 1, 'tag_id' => 2, 'name' => 'pivot'],
            ]);
    }
}

#[Table('tide_tags')]
final class ThirdTag extends Model
{
    public int $id;
    public string $name;
}

#[Table('tide_books')]
final class ThirdBook extends Model
{
    public int $id;
    public string $title;

    /** @var list<ThirdTag> */
    #[BelongsToMany(ThirdTag::class, pivot: 'tide_taggings', key: 'book_id', relatedKey: 'tag_id')]
    public array $tags;
}

#[Table('tide_stamped_retry')]
final class RetryStamped extends Model
{
    public int $id;
    public string $name;
    public ?\DateTimeImmutable $created_at;
    public ?\DateTimeImmutable $updated_at;
}

#[Table('tide_stamped_zone')]
final class ZoneStamped extends Model
{
    public int $id;
    public string $name;
    public ?\DateTimeImmutable $created_at;
    public ?\DateTimeImmutable $updated_at;
}

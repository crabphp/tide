<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Testing\FrozenClock;
use Crab\Tide\BelongsTo;
use Crab\Tide\BelongsToMany;
use Crab\Tide\HasMany;
use Crab\Tide\HasOne;
use Crab\Tide\Model;
use Crab\Tide\ModelMap;
use Crab\Tide\Names;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tide;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A model that only extends Model: its table, its times and its relations' keys are named after the classes.
 */
final class ConventionsTest extends DatabaseTestCase
{
    private const TABLES = ['tide_label_tide_novel', 'tide_labels', 'tide_covers', 'tide_novels', 'tide_writers'];

    public function testNamesAreTakenFromTheClass(): void
    {
        self::assertSame('order_items', Names::table('App\\Models\\OrderItem'));
        self::assertSame('categories', Names::table('App\\Category'));
        self::assertSame('boxes', Names::table('Box'));
        self::assertSame('days', Names::table('Day'));
        self::assertSame('http_requests', Names::table('HTTPRequest'));
        self::assertSame('order_item_id', Names::key('App\\Models\\OrderItem'));
        self::assertSame('book_tag', Names::pivot('App\\Tag', 'App\\Book'));

        self::assertSame('tide_novels', ModelMap::of(TideNovel::class)->table);
        self::assertSame('people', ModelMap::of(TidePerson::class)->table);
        self::assertSame('uuid', ModelMap::of(TidePerson::class)->key?->column);
    }

    #[DataProvider('drivers')]
    public function testAPlainModelReadsSavesAndIsStamped(string $driver): void
    {
        $this->conventions($driver);
        $writer = new TideWriter();
        $writer->name = 'Ada';
        $writer->save();
        self::assertSame('2026-06-07 08:09:10', $writer->created_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-07 08:09:10', $writer->updated_at?->format('Y-m-d H:i:s'));
        self::assertSame('Ada', TideWriter::find($writer->id)?->name);
    }

    #[DataProvider('drivers')]
    public function testRelationsFindTheirKeysByName(string $driver): void
    {
        $this->conventions($driver);
        $ada = $this->writer('Ada');
        $grace = $this->writer('Grace');
        $first = $this->novel('First', $ada->id);
        $second = $this->novel('Second', $ada->id);
        $this->novel('Third', $grace->id);
        $this->db->table('tide_covers')->insert(['tide_novel_id' => $first->id, 'colour' => 'red']);
        $php = $this->db->table('tide_labels')->insert(['name' => 'php']);
        $sql = $this->db->table('tide_labels')->insert(['name' => 'sql']);
        $this->db
            ->table('tide_label_tide_novel')
            ->insertMany([
                ['tide_novel_id' => $first->id, 'tide_label_id' => $php],
                ['tide_novel_id' => $first->id, 'tide_label_id' => $sql],
                ['tide_novel_id' => $second->id, 'tide_label_id' => $php],
            ]);

        // A property that is a model belongs to it, with no attribute at all.
        $novel = TideNovel::query()->with('writer', 'cover', 'labels')->find($first->id);
        self::assertSame('Ada', $novel->writer?->name);
        self::assertSame('red', $novel->cover?->colour);
        self::assertSame(
            ['php', 'sql'],
            array_map(static fn(TideLabel $label): string => $label->name, $novel->labels),
        );

        $writer = TideWriter::query()->with('novels')->find($ada->id);
        self::assertSame(['First', 'Second'], array_map(static fn(TideNovel $n): string => $n->title, $writer->novels));
        self::assertSame(2, $ada->related('novels')->count());
        self::assertSame(
            1,
            TideWriter::query()
                ->whereHas('novels', static fn($q) => $q->where('title', 'Third'))
                ->count(),
        );
        self::assertSame(
            ['First', 'Second'],
            TideNovel::query()
                ->whereHas('labels', static fn($q) => $q->where('name', 'php'))
                ->orderBy('id')
                ->pluck('title'),
        );

        // Changing the key the convention found unloads the relation, as a named key does.
        $novel->writer_id = $grace->id;
        $novel->save();
        self::assertFalse(isset($novel->writer));
    }

    public function testAKeyTheConventionCannotFindSaysWhatToWrite(): void
    {
        try {
            ModelMap::of(TideOrphan::class);
            self::fail('The missing key should have been refused.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('"owner_id"', $error->getMessage());
            self::assertStringContainsString("name the relation's real key in its attribute", $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach (self::TABLES as $table) {
                $this->db->execute('drop table if exists ' . $table);
            }
        }
        parent::tearDown();
    }

    private function conventions(string $driver): void
    {
        $this->db = $this->database($driver);
        Tide::use($this->db, new FrozenClock('2026-06-07 08:09:10'));
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        foreach (self::TABLES as $table) {
            $this->db->execute('drop table if exists ' . $table);
        }
        $this->db->execute(
            "create table tide_writers (id {$id}, name varchar(100) not null, created_at datetime null, updated_at datetime null)",
        );
        $this->db->execute(
            "create table tide_novels (id {$id}, title varchar(100) not null, writer_id integer not null)",
        );
        $this->db->execute(
            "create table tide_covers (id {$id}, tide_novel_id integer not null, colour varchar(20) not null)",
        );
        $this->db->execute("create table tide_labels (id {$id}, name varchar(100) not null)");
        $this->db->execute(
            'create table tide_label_tide_novel (tide_novel_id integer not null, tide_label_id integer not null)',
        );
    }

    private function writer(string $name): TideWriter
    {
        $writer = new TideWriter();
        $writer->name = $name;
        return $writer->save();
    }

    private function novel(string $title, int $writer): TideNovel
    {
        $novel = new TideNovel();
        $novel->title = $title;
        $novel->writer_id = $writer;
        return $novel->save();
    }
}

final class TideWriter extends Model
{
    public int $id;
    public string $name;
    public ?\DateTimeImmutable $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;

    /** @var list<TideNovel> */
    #[HasMany(TideNovel::class, 'writer_id')]
    public array $novels;
}

final class TideNovel extends Model
{
    public int $id;
    public string $title;
    public int $writer_id;

    public ?TideWriter $writer;

    #[HasOne]
    public ?TideCover $cover;

    /** @var list<TideLabel> */
    #[BelongsToMany(TideLabel::class)]
    public array $labels;
}

final class TideCover extends Model
{
    public int $id;
    public int $tide_novel_id;
    public string $colour;
}

final class TideLabel extends Model
{
    public int $id;
    public string $name;
}

#[Table('people', key: 'uuid')]
final class TidePerson extends Model
{
    public string $uuid;
}

final class TideOrphan extends Model
{
    public int $id;

    #[BelongsTo]
    public ?TideWriter $owner;
}

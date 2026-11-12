<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\Model;
use Crab\Tide\ModelQuery;
use Crab\Tide\MorphedByMany;
use Crab\Tide\MorphToMany;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

#[Table('tide_posts')]
final class TaggedPost extends Model
{
    public int $id;
    public string $title;

    /** @var list<Label> */
    #[MorphToMany(Label::class, 'taggable', pivot: 'tide_taggables', relatedKey: 'label_id')]
    public array $labels;
}

#[Table('tide_videos', morph: 'video')]
final class TaggedVideo extends Model
{
    public int $id;
    public string $title;

    /** @var list<Label> */
    #[MorphToMany(Label::class, 'taggable', pivot: 'tide_taggables', relatedKey: 'label_id')]
    public array $labels;
}

#[Table('tide_labels')]
final class Label extends Model
{
    public int $id;
    public string $name;

    /** @var list<TaggedPost> */
    #[MorphedByMany(TaggedPost::class, 'taggable', pivot: 'tide_taggables', key: 'label_id')]
    public array $posts;

    /** @var list<TaggedVideo> */
    #[MorphedByMany(TaggedVideo::class, 'taggable', pivot: 'tide_taggables', key: 'label_id')]
    public array $videos;
}

/**
 * Many to many with models of several kinds through one pivot, told apart by its
 * type column, read both ways and written with attach(), detach() and sync().
 */
final class MorphToManyTest extends DatabaseTestCase
{
    private const TABLES = ['tide_posts', 'tide_videos', 'tide_labels', 'tide_taggables'];

    private function seed(string $driver): void
    {
        $this->start($driver);
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        foreach ([
            'tide_posts' => "id {$id}, title varchar(100) not null",
            'tide_videos' => "id {$id}, title varchar(100) not null",
            'tide_labels' => "id {$id}, name varchar(100) not null",
            'tide_taggables' => 'label_id integer not null, taggable_type varchar(20) not null, taggable_id integer not null',
        ] as $table => $columns) {
            $this->db->execute('drop table if exists ' . $table);
            $this->db->execute('create table ' . $table . ' (' . $columns . ')');
        }
        // Post 1 and video 1 share an id, so only the type column tells their labels apart.
        $this->db
            ->table('tide_posts')
            ->insertMany([['id' => 1, 'title' => 'Post one'], ['id' => 2, 'title' => 'Post two']]);
        $this->db->table('tide_videos')->insert(['id' => 1, 'title' => 'Video one']);
        $this->db
            ->table('tide_labels')
            ->insertMany([['id' => 1, 'name' => 'php'], ['id' => 2, 'name' => 'sql'], ['id' => 3, 'name' => 'tv']]);
        $this->db
            ->table('tide_taggables')
            ->insertMany([
                ['label_id' => 1, 'taggable_type' => 'tide_posts', 'taggable_id' => 1],
                ['label_id' => 2, 'taggable_type' => 'tide_posts', 'taggable_id' => 1],
                ['label_id' => 3, 'taggable_type' => 'video', 'taggable_id' => 1],
                ['label_id' => 1, 'taggable_type' => 'video', 'taggable_id' => 1],
            ]);
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

    /**
     * @param list<Model> $models
     * @return list<string>
     */
    private static function names(array $models): array
    {
        return array_map(static fn(Label $label): string => $label->name, $models);
    }

    #[DataProvider('drivers')]
    public function testEachSideReadsOnlyTheLinksOfItsType(string $driver): void
    {
        $this->seed($driver);
        $post = TaggedPost::query()->with('labels')->find(1);
        self::assertSame(['php', 'sql'], self::names($post->labels));
        $video = TaggedVideo::query()->with('labels')->find(1);
        self::assertSame(['php', 'tv'], self::names($video->labels));

        $php = Label::query()->with('posts', 'videos')->find(1);
        self::assertSame(['Post one'], array_column($php->posts, 'title'));
        self::assertSame(['Video one'], array_column($php->videos, 'title'));
        self::assertSame([], Label::query()->with('posts')->find(3)->posts, 'tv labels a video only.');

        self::assertSame(['php', 'sql'], self::names($post->related('labels')->orderBy('id')->get()));
        self::assertSame(
            ['php'],
            self::names($post->related('labels')->where('name', 'php')->orWhere('name', 'tv')->get()),
            "An orWhere() cannot reach the video's label with the same parent id.",
        );
        self::assertSame(2, $post->related('labels')->count());
        self::assertSame(
            ['Post one'],
            array_column(
                TaggedPost::query()->whereHas('labels', static fn(ModelQuery $q): ModelQuery => $q->where(
                    'name',
                    'php',
                ))->get(),
                'title',
            ),
        );
        self::assertSame(
            [],
            TaggedPost::query()->whereHas('labels', static fn(ModelQuery $q): ModelQuery => $q->where(
                'name',
                'tv',
            ))->get(),
        );
    }

    #[DataProvider('drivers')]
    public function testAttachDetachAndSyncWriteOnlyThisModelsLinks(string $driver): void
    {
        $this->seed($driver);
        $post = TaggedPost::query()->with('labels')->find(1);
        $post->attach('labels', [3, 1, 3]);
        self::assertFalse(isset($post->labels), 'Links loaded before the change are unloaded.');
        self::assertSame(['php', 'sql', 'tv'], self::names($post->related('labels')->orderBy('id')->get()));
        self::assertSame(
            1,
            $this->db
                ->table('tide_taggables')
                ->where(['label_id' => 1, 'taggable_type' => 'tide_posts', 'taggable_id' => 1])
                ->count(),
            'A link already there is not repeated.',
        );

        $post->detach('labels', [2, 99]);
        self::assertSame(['php', 'tv'], self::names($post->related('labels')->orderBy('id')->get()));

        $post->sync('labels', [2]);
        self::assertSame(['sql'], self::names($post->related('labels')->get()));
        $post->sync('labels', []);
        self::assertSame(0, $post->related('labels')->count());

        // Video 1's links, under the same id, were never touched.
        self::assertSame(['php', 'tv'], self::names(TaggedVideo::find(1)->related('labels')->orderBy('id')->get()));
        // From the other side, the type written is the related model's.
        Label::find(2)->attach('videos', [1]);
        self::assertSame(
            ['php', 'sql', 'tv'],
            self::names(TaggedVideo::find(1)->related('labels')->orderBy('id')->get()),
        );
    }

    #[DataProvider('drivers')]
    public function testASyncRolledBackLeavesTheLinksAsTheyWere(string $driver): void
    {
        $this->seed($driver);
        try {
            $this->db->transaction(static function (): never {
                TaggedPost::find(1)->sync('labels', [3]);
                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException) {
            // Rolled back.
        }
        self::assertSame(['php', 'sql'], self::names(TaggedPost::find(1)->related('labels')->orderBy('id')->get()));
    }

    #[DataProvider('drivers')]
    public function testAPlainManyToManyIsWrittenTheSameWay(string $driver): void
    {
        $this->start($driver);
        $book = Book::find(2);
        $book->sync('tags', [2]);
        self::assertSame(['SQL'], array_column($book->related('tags')->get(), 'name'));
        self::assertSame(
            [1, 2],
            array_map(
                intval(...),
                $this->db->table('tide_book_tag')->where('book_id', 1)->orderBy('tag_id')->pluck('tag_id'),
            ),
            "Another book's links stay.",
        );
    }

    public function testMistakesAreRefusedBeforeAnythingIsWritten(): void
    {
        $this->seed('sqlite');
        foreach ([
            'is not a many-to-many relation' => static fn() => Book::find(1)->attach('author', [1]),
            'needs a stored model' => static fn() => new TaggedPost()->attach('labels', [1]),
            "was given string as a key; give the related models' int keys" => static fn() => TaggedPost::find(
                1,
            )->attach('labels', ['1']),
        ] as $message => $make) {
            try {
                $make();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        self::assertSame(4, $this->db->table('tide_taggables')->count());
    }
}

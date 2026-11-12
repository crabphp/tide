<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\Model;
use Crab\Tide\ModelQuery;
use Crab\Tide\MorphMany;
use Crab\Tide\MorphOne;
use Crab\Tide\MorphTo;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

#[Table('tide_posts')]
final class MorphPost extends Model
{
    public int $id;
    public string $title;

    #[MorphMany(MorphComment::class)]
    public array $comments;

    #[MorphOne]
    public ?MorphImage $image;
}

#[Table('tide_videos', morph: 'video')]
final class MorphVideo extends Model
{
    public int $id;
    public string $title;

    #[MorphMany(MorphComment::class)]
    public array $comments;
}

#[Table('tide_comments')]
final class MorphComment extends Model
{
    public int $id;
    public string $commentable_type;
    public int $commentable_id;
    public string $body;

    #[MorphTo]
    public MorphPost|MorphVideo $commentable;

    public function __construct(string $body)
    {
        $this->body = $body;
    }
}

#[Table('tide_images')]
final class MorphImage extends Model
{
    public int $id;
    public ?string $imageable_type;
    public ?int $imageable_id;
    public string $url;

    #[MorphTo]
    public MorphPost|MorphVideo|null $imageable;
}

#[Table('tide_comments')]
final class UnmarkedComment extends Model
{
    public int $id;
    public MorphPost|MorphVideo $commentable;
}

#[Table('tide_posts', morph: 'video')]
final class ClashingPost extends Model
{
    public int $id;
}

#[Table('tide_comments')]
final class ClashingComment extends Model
{
    public int $id;
    public string $commentable_type;
    public int $commentable_id;

    #[MorphTo]
    public ClashingPost|MorphVideo $commentable;
}

#[Table('tide_posts')]
final class LonelyPost extends Model
{
    public int $id;

    #[MorphMany(MorphComment::class)]
    public array $comments;
}

/**
 * Relations to one of several models, told apart by a type column holding
 * each model's morph name: its table's name unless said.
 */
final class MorphTest extends DatabaseTestCase
{
    private const TABLES = ['tide_posts', 'tide_videos', 'tide_comments', 'tide_images'];

    private function seed(string $driver): void
    {
        $this->start($driver);
        $id = $driver === 'sqlite' ? 'integer primary key autoincrement' : 'integer auto_increment primary key';
        foreach ([
            'tide_posts' => "id {$id}, title varchar(100) not null",
            'tide_videos' => "id {$id}, title varchar(100) not null",
            'tide_comments' => "id {$id}, commentable_type varchar(20) not null, commentable_id integer not null, body varchar(100) not null",
            'tide_images' => "id {$id}, imageable_type varchar(20) null, imageable_id integer null, url varchar(100) not null",
        ] as $table => $columns) {
            $this->db->execute('drop table if exists ' . $table);
            $this->db->execute('create table ' . $table . ' (' . $columns . ')');
        }
        // Post 1 and video 1 share an id, so only the type column tells their comments apart.
        $this->db
            ->table('tide_posts')
            ->insertMany([['id' => 1, 'title' => 'Post one'], ['id' => 2, 'title' => 'Post two']]);
        $this->db->table('tide_videos')->insert(['id' => 1, 'title' => 'Video one']);
        $this->db
            ->table('tide_comments')
            ->insertMany([
                ['id' => 1, 'commentable_type' => 'tide_posts', 'commentable_id' => 1, 'body' => 'on post 1'],
                ['id' => 2, 'commentable_type' => 'video', 'commentable_id' => 1, 'body' => 'on video 1'],
                ['id' => 3, 'commentable_type' => 'tide_posts', 'commentable_id' => 1, 'body' => 'again on post 1'],
                ['id' => 4, 'commentable_type' => 'tide_posts', 'commentable_id' => 2, 'body' => 'on post 2'],
            ]);
        $this->db
            ->table('tide_images')
            ->insertMany([
                ['id' => 1, 'imageable_type' => 'tide_posts', 'imageable_id' => 2, 'url' => 'two.png'],
                ['id' => 2, 'imageable_type' => null, 'imageable_id' => null, 'url' => 'loose.png'],
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
    private static function bodies(array $models): array
    {
        return array_map(static fn(MorphComment $comment): string => $comment->body, $models);
    }

    #[DataProvider('drivers')]
    public function testManyAndOneReadOnlyTheRowsWhoseTypeNamesTheModel(string $driver): void
    {
        $this->seed($driver);
        $posts = MorphPost::query()->with('comments', 'image')->orderBy('id')->get();
        self::assertSame(['on post 1', 'again on post 1'], self::bodies($posts[0]->comments));
        self::assertSame(['on post 2'], self::bodies($posts[1]->comments));
        self::assertNull($posts[0]->image);
        self::assertSame('two.png', $posts[1]->image->url);

        $video = MorphVideo::query()->with('comments')->find(1);
        self::assertSame(['on video 1'], self::bodies($video->comments));
    }

    #[DataProvider('drivers')]
    public function testMorphToReadsEachModelItMayBelongToByItsMorphName(string $driver): void
    {
        $this->seed($driver);
        $comments = MorphComment::query()->with('commentable')->orderBy('id')->get();
        self::assertSame(
            [MorphPost::class, MorphVideo::class, MorphPost::class, MorphPost::class],
            array_map(static fn(MorphComment $comment): string => $comment->commentable::class, $comments),
        );
        self::assertSame('Video one', $comments[1]->commentable->title);
        self::assertSame(
            $comments[0]->commentable,
            $comments[2]->commentable,
            'One object for one row, within a load.',
        );

        $images = MorphImage::query()->with('imageable')->orderBy('id')->get();
        self::assertSame('Post two', $images[0]->imageable->title);
        self::assertNull($images[1]->imageable, 'A null type is no owner, for a nullable MorphTo.');

        // A nested path is read on each model the MorphTo may belong to.
        $nested = MorphComment::query()->with('commentable.comments')->find(2);
        self::assertSame(['on video 1'], self::bodies($nested->commentable->comments));
    }

    #[DataProvider('drivers')]
    #[DataProvider('drivers')]
    public function testRelatedAndWhereHasKeepToTheModelsType(string $driver): void
    {
        $this->seed($driver);
        $post = MorphPost::find(1);
        self::assertSame(
            ['on post 1', 'again on post 1'],
            self::bodies($post->related('comments')->orderBy('id')->get()),
        );
        self::assertSame(
            ['on post 1'],
            self::bodies(
                $post
                    ->related('comments')
                    ->where('body', 'like', 'on%')
                    ->orWhere('body', 'on video 1')
                    ->orderBy('id')
                    ->get(),
            ),
            'An orWhere() cannot reach a video\'s comment with the same id.',
        );
        self::assertSame(
            ['Post one', 'Post two'],
            array_column(MorphPost::query()->whereHas('comments')->orderBy('id')->get(), 'title'),
        );
        self::assertSame(
            ['Post one'],
            array_column(
                MorphPost::query()->whereHas('comments', static fn(ModelQuery $query): ModelQuery => $query->where(
                    'body',
                    'again on post 1',
                ))->get(),
                'title',
            ),
        );
        self::assertSame(
            [],
            array_column(
                MorphPost::query()->whereHas('comments', static fn(ModelQuery $query): ModelQuery => $query->where(
                    'body',
                    'on video 1',
                ))->get(),
                'title',
            ),
        );
    }

    #[DataProvider('drivers')]
    public function testAssociatePointsAMorphToAndABelongsToAtAModel(string $driver): void
    {
        $this->seed($driver);
        $comment = new MorphComment('new on video');
        $comment->associate('commentable', MorphVideo::find(1))->save();
        self::assertSame(
            ['commentable_type' => 'video', 'commentable_id' => 1],
            $this->db
                ->table('tide_comments')
                ->select('commentable_type', 'commentable_id')
                ->where('id', $comment->id)
                ->first(),
        );
        self::assertSame('Video one', $comment->commentable->title);

        $comment->associate('commentable', MorphPost::find(2))->save();
        self::assertSame(
            'tide_posts',
            $this->db->table('tide_comments')->where('id', $comment->id)->value('commentable_type'),
        );

        $image = MorphImage::find(1);
        $image->associate('imageable', null)->save();
        self::assertNull($this->db->table('tide_images')->where('id', 1)->value('imageable_type'));

        self::assertSame(['tide_posts', 'video'], [MorphPost::morphName(), MorphVideo::morphName()]);
    }

    #[DataProvider('drivers')]
    public function testChangingTheTypeOrIdUnloadsTheMorphTo(string $driver): void
    {
        $this->seed($driver);
        $comment = MorphComment::query()->with('commentable')->find(1);
        $comment->commentable_id = 2;
        $comment->save();
        self::assertFalse(new \ReflectionProperty($comment, 'commentable')->isInitialized($comment));
    }

    #[DataProvider('drivers')]
    public function testARefusedAssociationLeavesTheModelAsItWas(string $driver): void
    {
        $this->seed($driver);
        $comment = MorphComment::query()->with('commentable')->find(1);
        foreach ([new MorphVideo(), new MorphComment('not an owner')] as $wrong) {
            try {
                $comment->associate('commentable', $wrong);
                self::fail('Not refused.');
            } catch (\LogicException) {
                // Refused as it should be; what matters is what is left.
            }
        }
        self::assertSame(['tide_posts', 1], [$comment->commentable_type, $comment->commentable_id]);
        self::assertInstanceOf(MorphPost::class, $comment->commentable);
        $comment->body = 'an unrelated edit';
        $comment->save();
        self::assertSame(
            ['commentable_type' => 'tide_posts', 'commentable_id' => 1, 'body' => 'an unrelated edit'],
            $this->db
                ->table('tide_comments')
                ->select('commentable_type', 'commentable_id', 'body')
                ->where('id', 1)
                ->first(),
        );
    }

    public function testARowWhoseTypeTheUnionDoesNotListIsRefused(): void
    {
        $this->seed('sqlite');
        $this->db->table('tide_comments')->where('id', 1)->update(['commentable_type' => 'photos']);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'has the type "photos", which is none of its models\' morph names (tide_posts, video)',
        );
        MorphComment::query()->with('commentable')->get();
    }

    public function testMappingMistakesAreRefusedBeforeAnyRowIsRead(): void
    {
        $this->seed('sqlite');
        foreach ([
            'is a union of models; mark it #[MorphTo]' => static fn() => UnmarkedComment::query(),
            'share the morph name "video"' => static fn() => ClashingComment::query()->with('commentable')->get(),
            'needs a #[MorphTo] on ' . MorphComment::class . ' that lists ' . LonelyPost::class =>
                static fn() => LonelyPost::query()->with('comments')->get(),
        ] as $message => $make) {
            try {
                $make();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testMistakesAreRefusedWithWhatToWrite(): void
    {
        $this->seed('sqlite');
        foreach ([
            'belongs to one of several models, so whereHas()' => static fn() => MorphComment::query()->whereHas(
                'commentable',
            )->get(),
            'belongs to one of several models; load it with with(' => static fn() => MorphComment::find(1)->related(
                'commentable',
            ),
            'cannot point at a ' . MorphComment::class => static fn() => new MorphComment('x')->associate(
                'commentable',
                MorphComment::find(1),
            ),
            'can point only at a stored' => static fn() => new MorphComment('x')->associate(
                'commentable',
                new MorphPost(),
            ),
            'is not a BelongsTo or a MorphTo' => static fn() => MorphPost::find(1)->associate('comments', null),
            'cannot point at none' => static fn() => MorphComment::find(1)->associate('commentable', null),
        ] as $message => $make) {
            try {
                $make();
                self::fail("Not refused: {$message}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }
}

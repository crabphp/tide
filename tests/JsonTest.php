<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Status;
use PHPUnit\Framework\Attributes\DataProvider;

final class JsonTest extends DatabaseTestCase
{
    #[DataProvider('drivers')]
    public function testAModelCanSayHowItsDatesAreWritten(string $driver): void
    {
        $this->start($driver);
        $this->db->table('tide_books')->where('id', 1)->update(['published_at' => '2026-05-06 07:08:09']);
        self::assertSame('2026-05-06T07:08:09+00:00', Book::find(1)->jsonSerialize()['publishedAt']);
        self::assertSame('2026-05-06 07:08:09', PlainDateBook::find(1)->jsonSerialize()['published_at']);
    }

    #[DataProvider('drivers')]
    public function testJsonContainsColumnsDatesEnumsAndOnlyLoadedVisibleRelations(string $driver): void
    {
        $this->start($driver);
        $this->db
            ->table('tide_books')
            ->where('id', 1)
            ->update([
                'published_at' => '2026-02-03 04:05:06',
                'status' => Status::Published->value,
                'meta' => '{"a":[1,2]}',
            ]);
        $book = Book::find(1);
        $json = json_decode(json_encode($book, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['id', 'title', 'author_id', 'status', 'votes', 'meta', 'publishedAt'], array_keys($json));
        self::assertSame('published', $json['status']);
        self::assertSame(['a' => [1, 2]], $json['meta']);
        self::assertSame($book->publishedAt->format(\DateTimeInterface::ATOM), $json['publishedAt']);
        self::assertArrayNotHasKey('secret', $json);
        self::assertArrayNotHasKey('author', $json);
        Book::load([$book], 'author', 'chapters.notes');
        $json = json_decode(json_encode($book, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Ada', $json['author']['name']);
        self::assertSame('A note', $json['chapters'][1]['notes'][0]['body']);
        self::assertArrayNotHasKey('books', $json['author']);
    }

    public function testNewModelsSerializeOnlyInitializedPublicProperties(): void
    {
        $book = new Book('New');
        self::assertSame(
            ['title' => 'New', 'author_id' => null],
            json_decode(json_encode($book, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        );
    }
}

#[\Crab\Tide\Table('tide_books')]
final class PlainDateBook extends \Crab\Tide\Model
{
    public int $id;
    public string $title;
    public ?\DateTimeImmutable $published_at;

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}

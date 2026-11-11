<?php

declare(strict_types=1);

namespace Crab\Tide\Tests\Support;

use Crab\Contracts\Database\Cast;
use Crab\Database\ColumnName;
use Crab\Database\Json;
use Crab\Tide\BelongsTo;
use Crab\Tide\BelongsToMany;
use Crab\Tide\HasMany;
use Crab\Tide\HasOne;
use Crab\Tide\Hidden;
use Crab\Tide\Model;
use Crab\Tide\Table;

enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
}

#[Table('tide_authors')]
final class Author extends Model
{
    public int $id;
    public string $name;
    #[HasMany(Book::class, 'author_id')]
    public array $books;
}

#[Table('tide_books')]
final class Book extends Model
{
    public int $id;
    public string $title;
    public ?int $author_id;
    public Status $status;
    public int $votes;
    #[Json]
    public array $meta;
    #[ColumnName('published_at')]
    public ?\DateTimeImmutable $publishedAt;
    #[Hidden]
    public string $secret;
    #[BelongsTo('author_id')]
    public ?Author $author;
    #[HasMany(Chapter::class, 'book_id')]
    public array $chapters;
    #[HasOne('book_id')]
    public ?Isbn $isbn;
    #[BelongsToMany(Tag::class, 'tide_book_tag', 'book_id', 'tag_id')]
    public array $tags;

    public function __construct(string $title = 'New book', ?int $authorId = null)
    {
        $this->title = $title;
        $this->author_id = $authorId;
    }
}

#[Table('tide_chapters')]
final class Chapter extends Model
{
    public int $id;
    public int $book_id;
    public string $title;
    #[BelongsTo('book_id')]
    public Book $book;
    #[HasMany(Note::class, 'chapter_id')]
    public array $notes;
}

#[Table('tide_notes')]
final class Note extends Model
{
    public int $id;
    public int $chapter_id;
    public string $body;
}

#[Table('tide_isbns')]
final class Isbn extends Model
{
    public int $id;
    public int $book_id;
    public string $code;
}

#[Table('tide_tags')]
final class Tag extends Model
{
    public int $id;
    public string $name;
    #[BelongsToMany(Book::class, 'tide_book_tag', 'tag_id', 'book_id')]
    public array $books;
}

#[Table('tide_categories')]
final class Category extends Model
{
    public int $id;
    public ?int $parent_id;
    public string $name;
    #[BelongsTo('parent_id')]
    public ?Category $parent;
    #[HasMany(self::class, 'parent_id')]
    public array $children;
}

final class Money
{
    public function __construct(
        public int $cents,
    ) {}
}

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final readonly class AsMoney implements Cast
{
    public function read(mixed $value): Money
    {
        return new Money((int) $value);
    }

    public function write(mixed $value): int
    {
        return $value->cents;
    }
}

#[Table('tide_prices')]
final class Price extends Model
{
    public int $id;
    #[AsMoney]
    public Money $amount;
}

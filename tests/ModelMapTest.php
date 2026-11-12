<?php

declare(strict_types=1);

namespace Crab\Tide\Tests;

use Crab\Database\ColumnName;
use Crab\Database\Json;
use Crab\Tide\BelongsTo;
use Crab\Tide\BelongsToMany;
use Crab\Tide\HasMany;
use Crab\Tide\HasOne;
use Crab\Tide\Model;
use Crab\Tide\ModelMap;
use Crab\Tide\Table;
use Crab\Tide\Tests\Support\AsMoney;
use Crab\Tide\Tests\Support\Book;
use Crab\Tide\Tests\Support\DatabaseTestCase;
use Crab\Tide\Tests\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;

final class ModelMapTest extends DatabaseTestCase
{
    public function testParentsVirtualPropertiesHooksAndConstructionAreMappedOnce(): void
    {
        $map = ModelMap::of(MappedModel::class);
        self::assertSame($map, ModelMap::of(MappedModel::class));
        self::assertSame(['id', 'name', 'settings', 'price'], array_keys($map->fields));
        self::assertSame(['id', 'display_name', 'settings', 'price'], array_keys($map->columns));
        $model = $map->hydrate(['id' => '1', 'display_name' => ' raw ', 'settings' => '{"a":1}', 'price' => '42']);
        self::assertSame(' raw ', $model->name);
        self::assertSame(' RAW ', $model->uppercase);
        self::assertSame(['a' => 1], $model->settings);
        self::assertSame(42, $model->price->cents);
        self::assertTrue($model->stored());
        $model->name = ' trim ';
        self::assertSame('trim', $model->name);
        self::assertSame(['book'], array_keys($map->relations));
    }

    #[DataProvider('invalidModels')]
    public function testInvalidDeclarationsAreRefusedWithTheRepair(string $class, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        ModelMap::of($class);
    }

    public static function invalidModels(): array
    {
        return [
            [\stdClass::class, 'extending Crab\\Tide\\Model'],
            ['MissingTideClass', 'extending Crab\\Tide\\Model'],
            [AbstractModel::class, 'query a concrete model'],
            [UntypedModel::class, 'has no type; declare one'],
            [UnionModel::class, 'give it one type, or a cast'],
            [ReadonlyFieldModel::class, 'declare a writable public property'],
            [MutableDateModel::class, 'declare it DateTimeImmutable'],
            [ArrayModel::class, 'mark a JSON column #[Json]'],
            [ObjectModel::class, 'give it a cast'],
            [DuplicateModel::class, 'both map to the column'],
            [BadKeyModel::class, 'non-nullable int or string'],
            [NullableKeyModel::class, 'non-nullable int or string'],
            [TwoRelationsModel::class, 'declare one relation'],
            [RelationCastModel::class, 'remove its cast and #[ColumnName]'],
            [RelationColumnModel::class, 'remove its cast and #[ColumnName]'],
            [DefaultRelationModel::class, 'without a default'],
            [PromotedRelationModel::class, 'outside the constructor'],
            [BadSingleModel::class, 'declare a Model subclass'],
            [BadManyModel::class, 'declare array'],
            [BadRelatedModel::class, 'name a class extending'],
            [MissingForeignModel::class, 'needs the column "book_id"'],
            [BadForeignModel::class, 'declare it as an int or string'],
            [TwoCastsModel::class, 'has two casts; give it one'],
            [ReadonlyRelationModel::class, 'declare a writable relation'],
            [InvalidColumnModel::class, 'write #[ColumnName('],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testNamesAreCheckedWhenAnAttributeIsMade(string $class, array $arguments): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('use letters, digits and underscores');
        new $class(...$arguments);
    }

    public static function invalidNames(): array
    {
        return [
            [Table::class, ['bad.name']],
            [Table::class, ['okay', 'bad-key']],
            [BelongsTo::class, ['bad key']],
            [BelongsTo::class, ['id', 'bad.key']],
            [HasOne::class, ['bad key']],
            [HasOne::class, ['id', 'bad.key']],
            [HasMany::class, [Book::class, 'bad key']],
            [HasMany::class, [Book::class, 'id', 'bad.key']],
            [BelongsToMany::class, [Book::class, 'bad pivot', 'id', 'id']],
            [BelongsToMany::class, [Book::class, 'pivot', 'bad key', 'id']],
            [BelongsToMany::class, [Book::class, 'pivot', 'id', 'bad key']],
        ];
    }

    public function testHydrationRequiresEveryColumnEvenWithADefault(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a model query reads every column its model declares');
        ModelMap::of(DefaultColumnModel::class)->hydrate(['id' => 1]);
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesNameThePropertyAndColumnWithoutLeakingThem(array $row, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);
        ModelMap::of(DefaultColumnModel::class)->hydrate($row);
    }

    public static function invalidValues(): array
    {
        return [
            [['id' => 'not-an-integer', 'name' => 'name'], '::$id takes int'],
            [['id' => 1, 'name' => null], '::$name cannot be null'],
        ];
    }

    public function testAKeylessModelCanBeReadButNotFound(): void
    {
        $this->start('sqlite');
        self::assertSame('Alpha', KeylessModel::query()->orderBy('title')->first()->title);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declare public int $id;');
        KeylessModel::find(1);
    }
}

abstract class ParentModel extends Model
{
    public int $id;
    protected string $privateState;
    public static int $counter = 0;
}

#[Table('mapped')]
final class MappedModel extends ParentModel
{
    #[ColumnName('display_name')]
    public string $name {
        set => trim($value);
    }
    public string $uppercase {
        get => strtoupper($this->name);
    }
    #[Json]
    public array $settings;
    #[AsMoney]
    public Money $price;
    #[BelongsTo('id')]
    public ?Book $book;

    public function __construct()
    {
        throw new \LogicException('The constructor must not run.');
    }
}

abstract class AbstractModel extends Model {}

final class WithoutTable extends ParentModel {}

#[Table('bad')]
final class UntypedModel extends Model
{
    public $name;
}

#[Table('bad')]
final class UnionModel extends Model
{
    public int|string $name;
}

#[Table('bad')]
final class ReadonlyFieldModel extends Model
{
    public readonly int $id;
}

#[Table('bad')]
final class MutableDateModel extends Model
{
    public \DateTime $date;
}

#[Table('bad')]
final class ArrayModel extends Model
{
    public array $data;
}

#[Table('bad')]
final class ObjectModel extends Model
{
    public \stdClass $object;
}

#[Table('bad')]
final class DuplicateModel extends Model
{
    public int $id;
    #[ColumnName('id')]
    public int $other;
}

#[Table('bad')]
final class BadKeyModel extends Model
{
    public bool $id;
}

#[Table('bad')]
final class NullableKeyModel extends Model
{
    public ?int $id;
}

#[Table('bad')]
final class TwoRelationsModel extends Model
{
    #[BelongsTo('id'), HasOne('id')]
    public Book $book;
}

#[Table('bad')]
final class RelationCastModel extends Model
{
    #[HasMany(Book::class, 'id'), Json]
    public array $books;
}

#[Table('bad')]
final class RelationColumnModel extends Model
{
    #[HasMany(Book::class, 'id'), ColumnName('books')]
    public array $books;
}

#[Table('bad')]
final class DefaultRelationModel extends Model
{
    #[HasMany(Book::class, 'id')]
    public array $books = [];
}

#[Table('bad')]
final class PromotedRelationModel extends Model
{
    public function __construct(
        #[BelongsTo('id')]
        public Book $book,
    ) {}
}

#[Table('bad')]
final class BadSingleModel extends Model
{
    #[BelongsTo('id')]
    public int $book;
}

#[Table('bad')]
final class BadManyModel extends Model
{
    #[HasMany(Book::class, 'id')]
    public Book $books;
}

#[Table('bad')]
final class BadRelatedModel extends Model
{
    #[HasMany(\stdClass::class, 'id')]
    public array $books;
}

#[Table('bad')]
final class MissingForeignModel extends Model
{
    #[BelongsTo('book_id')]
    public Book $book;
}

#[Table('bad')]
final class BadForeignModel extends Model
{
    public bool $book_id;
    #[BelongsTo('book_id')]
    public Book $book;
}

#[Table('bad')]
final class TwoCastsModel extends Model
{
    #[Json, AsMoney]
    public array $data;
}

#[Table('bad')]
final class ReadonlyRelationModel extends Model
{
    #[HasMany(Book::class, 'id')]
    public readonly array $books;
}

#[Table('good')]
final class DefaultColumnModel extends Model
{
    public int $id;
    public string $name = 'default';
}

#[Table('tide_books')]
final class KeylessModel extends Model
{
    public string $title;
}

#[Table('bad')]
final class InvalidColumnModel extends Model
{
    #[ColumnName('not.a.column')]
    public int $id;
}

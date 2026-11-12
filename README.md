# Tide

Tide adds typed models and explicit relations to the [Crab framework](https://github.com/crabphp/framework). New to it? Start with the [guide by use case](docs/guide.md); this README is the reference. Use a model for a row you read, change and save, whole or some of its columns. Use the framework's query builder for arrays, and `into()` records for joins and reports.

Tide requires PHP 8.4 and `crabphp/framework` 0.7 or later. Install it with Composer:

```sh
composer require crabphp/tide
```

List the plugin in your application:

```php
// app.php
use Crab\App;
use Crab\Tide\TidePlugin;

return App::configure(__DIR__)
    ->withPlugins([TidePlugin::class])
    ->create();
```

The plugin resolves the application's database and clock on first use. In a standalone test, give Tide your database, and a clock as a second argument when the test holds the time still:

```php
// test-setup.php
use Crab\Tide\Tide;

Tide::use($database);
```

## Models

A model extends `Model` and declares its columns as typed properties. A constructor is for making a new model; reading a row never calls it. The database supplies an integer key when you leave it uninitialized, or zero. Supply a string key yourself.

```php
// models.php
use Crab\Tide\HasMany;
use Crab\Tide\Hidden;
use Crab\Tide\Model;

final class Author extends Model
{
    public int $id;

    #[HasMany(Book::class)]
    public array $books;

    public function __construct(public string $name) {}
}

final class Book extends Model
{
    public int $id;
    public ?\DateTimeImmutable $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;

    #[Hidden]
    public string $internal_note;

    public ?Author $author;

    public function __construct(public string $title, public ?int $author_id) {}
}
```

What a model leaves unsaid is named after its classes, and an attribute says it only where the name differs:

| | Unsaid | Said |
| --- | --- | --- |
| Table | The class in snake case and plural: `Book` reads `books`, `OrderItem` reads `order_items`, `Category` reads `categories` | `#[Table('people')]` |
| Key | `id` | `#[Table(key: 'uuid')]` |
| Times | `created_at` and `updated_at` are kept when declared as dates | `#[Timestamps(updated: 'modified_at')]`, or `null` for one kept by hand |
| A property that is another model | Belongs to it, by the property's name and `_id`: `$author` reads `author_id` | `#[BelongsTo('written_by')]` |
| `#[HasOne]`, `#[HasMany(Chapter::class)]` | The related table's column named after this model: `book_id` | `#[HasMany(Chapter::class, 'volume_id')]` |
| `#[BelongsToMany(Tag::class)]` | The pivot is both names in alphabetical order, `book_tag`, with `book_id` and `tag_id` | `#[BelongsToMany(Tag::class, pivot: 'taggings', key: 'item_id', relatedKey: 'tag_id')]` |

The plural is made with `s`, `es` or `ies`. A word English makes plural another way, `person`, is named with `#[Table]`. A name Tide assumed wrongly fails loudly, as the database's error naming the table or as Tide's naming the missing column, and one attribute corrects it.

Public, backed properties are columns; private, protected, static and virtual properties are not. Columns accept the framework's record types and casts, including backed enums, `DateTimeImmutable`, `#[Json]` arrays and custom `Cast` attributes. `#[ColumnName('created_at')]` maps another column name. Persistence reads and writes raw column values without running hooks, including stored keys and relation matching. Application reads and writes still run their hooks, and JSON uses the getter's view. Columns with `public private(set)` or `public protected(set)` are supported; readonly columns are refused. Relations must be plain public properties without hooks or asymmetric setter visibility.

`#[Table('books', key: 'uuid', database: 'reports')]` names the table, another key column and a configured database; each part is optional. Table, pivot and key names are identifiers, not SQL. A model without a key can be read, but cannot be found by key, saved, deleted, refreshed or queried through `related()`.

## Reading

A model query chooses rows of its model and returns models: conditions, `orderBy()`, `limit()`, `offset()` and `lockForUpdate()`, and relations through `with()`, `whereHas()` and `whereDoesntHave()`. A closure given to `where()` or `orWhere()` receives a `ModelQuery`, with its conditions grouped; the query cannot be run or copied inside it. `first()` and `find()` return a model or null, `get()` a list, and `exists()` and `count()` say whether and how many match:

```php
// reading.php
$books = Book::query()->where('title', 'like', '%PHP%')->orderBy('id')->get();
$book = Book::find(1);
$page = Book::query()->orderBy('id')->paginate(20);
$count = Book::query()->where('author_id', 1)->count();
```

A query reads every column the model declares, named one by one, never `*`. `simplePaginate()`, `cursorPaginate()` and `chunkById()` return models too; cursor pagination and chunking go by the key unless given a column, and `chunkById()` refuses an existing order. Running a query leaves it unchanged; clone it to branch.

### What is the query builder's

Tide maps rows and their relations and saves models. Everything else is the framework's query builder's, started on the database, with an `into()` record for a typed shape: a few columns, a join, an expression, a total, a raw condition, and writes to many rows at once.

```php
// records.php
final readonly class BookRow
{
    public function __construct(public int $id, public string $title, public ?string $author) {}
}

$rows = $database->current()->table('books')
    ->where('books.title', 'like', '%PHP%')
    ->leftJoin('authors', 'authors.id', '=', 'books.author_id')
    ->select('books.id', 'books.title', 'authors.name as author')
    ->into(BookRow::class)
    ->get();   // list<BookRow>

$names = $database->current()->table('authors')->orderBy('id')->pluck('name');
```

There is no handoff between the two: a model query returns models, the builder arrays or records. Where SQL decides which rows and models are wanted, give the builder's ids to `whereIn('id', ...)`. A model query has no joins, so a model is never repeated for each related row: filter by related rows with `whereHas()`.

## Relations

Declare a relation outside the constructor with no default. Reading it before loading is PHP's uninitialized-property error. `isset()` is false both for an unloaded relation and for a loaded null relation.

```php
// relations.php
use Crab\Tide\ModelQuery;

$books = Book::query()->with('author')->orderBy('id')->get();
Book::load($books, 'author');
$authors = Author::query()->with('books', static fn (ModelQuery $query): ModelQuery => $query->orderBy('id', 'desc'))->get();
$recent = Author::find(1)->related('books')->orderBy('id', 'desc')->limit(5)->get();
$withBooks = Author::query()->whereHas('books')->get();
$withoutBooks = Author::query()->whereDoesntHave('books')->get();
$grouped = Book::query()->where(static fn (ModelQuery $query): ModelQuery => $query->whereHas('author')->orWhere('title', 'PHP'))->get();
```

A property that is another model belongs to it with no attribute. `HasOne` declares a nullable or required model property. `HasMany` declares an array and names its related class, as `BelongsToMany` does. Each takes the names it would otherwise assume: `BelongsTo` a foreign-key column on this model and an optional owner-key column; `HasOne` and `HasMany` a related foreign-key column and an optional local-key column; `BelongsToMany` the pivot table and its two foreign-key columns. Omitted owner and local keys use the model's key.

A dotted path such as `books.author` loads through relations. A closure after a path constrains its last part. Loading uses one query per relation, or one per batch of 1,000 distinct keys. Related objects are shared within that load, and loading again replaces the property. Missing single rows become null only on nullable properties; many relations become empty arrays. With `with()` and `load()`, lists default to ascending related key order, and `HasOne` takes the first row in that order. `HasOne` and `BelongsTo` hydrate only the winning row for each parent key; the database still sends all matching rows. A `related()` query has only the order you give it: `related('one')->first()` without an order may return any matching row.

On a many-to-many relation, a condition or an order on a plain column name means the related model's column, whatever the pivot table calls its own. A `related()` query keeps its parent condition permanently: later model conditions are grouped within that parent, so an `orWhere()` cannot reach another parent's rows. Order, limit and `with()` still apply to the query.

A limit or offset in an eager constraint is refused; use `related()` for one model's limited query. A relation in another database reads that database's current connection, with a many-to-many pivot beside the related table. `whereHas()` and `whereDoesntHave()` cannot cross databases.

### Polymorphic relations

A row that belongs to one of several models, a comment on a post or on a video, has a type column and an id column. `#[MorphTo]` reads them, and its union type lists the models it may belong to:

```php
// morph.php
use Crab\Tide\MorphMany;
use Crab\Tide\MorphTo;

final class Post extends Model
{
    public int $id;

    #[MorphMany(Comment::class)]
    public array $comments;

    public function __construct(public string $title) {}
}

final class Comment extends Model
{
    public int $id;
    public string $commentable_type;
    public int $commentable_id;

    #[MorphTo]
    public Post|Book $commentable;

    public function __construct(public string $body) {}
}

$post = new Post('Hello');
$post->save();
$comment = new Comment('First!');
$comment->associate('commentable', $post)->save();   // commentable_type "posts", commentable_id the post's id

$commented = Post::query()->with('comments')->get();
$owners = Comment::query()->with('commentable')->get();
```

The type column holds each model's morph name: its table's name, `posts`, or `#[Table(morph: 'post')]`, so renaming a class never changes stored rows. The columns are named after the property, `commentable_type` and `commentable_id`, unless `#[MorphTo(type: ..., id: ...)]` names them. `#[MorphMany(Comment::class)]` and `#[MorphOne]` find the related model's `#[MorphTo]` that lists this model; `relation:` names it when there are several.

`with()` and `load()` read a `MorphTo` with one query per model it belongs to; a nested path past it must be a relation of each. A type the union does not list is refused, naming it and the morph names it may be. `MorphMany` and `MorphOne` work with `with()`, `load()`, `related()`, `whereHas()` and `whereDoesntHave()`, each reading only the rows whose type names this model. `whereHas()` cannot follow a `MorphTo`; condition its columns. `associate('commentable', $post)` sets the type and id columns, `associate('author', $author)` a `BelongsTo`'s key, and null points at none; `save()` writes them. `Post::morphName()` gives the name stored for a model.

## Writing

Make a model with `new`, then save it. Later saves write only changed columns:

```php
// writing.php
$author = new Author('Ada');
$author->save();
$book = new Book('PHP models', $author->id);
$book->save();
$book->title = 'Typed PHP models';
$book->save();
$book->refresh();
$copy = clone $book;
$book->delete();
$book->save();
```

A model that declares `created_at` and `updated_at` as dates has them kept, in PHP's default time zone, the one they are read back in: both when it is first saved, and `updated_at` at each change. A time you set yourself is written as you set it, and a save that changes nothing writes nothing. The time is the application's clock, to the second.

An insert writes what the model holds. A property never given a value, declared without a default, is left out, so the database gives the column its default; the model learns it when you `refresh()` it. Null, assigned or a property's `= null` default, is written as NULL, so the model and the row say the same. Declare a column whose database default you want without a PHP default. A database-numbered key and a kept time cannot have a hook when Tide must fill them in: PHP cannot take a hooked property back on rollback, so the save is refused before it writes.

Changing a foreign key and saving unsets its loaded `BelongsTo` property. Assigning a relation itself changes no foreign key. Arrays and scalars compare strictly, dates by their instant, and cast objects by their written value, including mutations inside them. Mutable `DateTime` values held by `DateTimeInterface` or `mixed` columns are snapshotted as immutable copies, so in-place date changes are detected. A failed statement leaves the snapshot untouched so you can correct the model and save again.

Deletes and updates use the stored key, even if you changed the key property. Delete makes the model new again and keeps loaded relations. Refresh reads into the same object, unloads relations and throws `RowGone` if the row disappeared. Clone keeps the original snapshot and still represents the same row.

Several models are saved together with `save()` for each inside a transaction. A write to many rows at once, an import, a counter, is the query builder's: `$database->current()->table('books')->where('author_id', 1)->increment('votes')`. It does not change models already held, and keeps no times; refresh a model where it matters.

## Transactions

Model calls share the database's current loan. A query captures that connection when it is created, so create it where it runs:

```php
// transactions.php
$database->transaction(static function (): void {
    $author = new Author('Grace');
    $author->save();
    $book = new Book('Transactions in PHP', $author->id);
    $book->save();
});
```

A query also retains its original database. Before executing or loading relations, a captured connection that is not the database itself must still be that database's `current()` connection. Otherwise Tide throws `LogicException`: its `run()` or transaction has ended, or another loan is innermost. Start the query where it runs. A query started outside any loan keeps using the pool, even when run inside a transaction.

A nested transaction on the callback's connection uses a savepoint. A nested call on the database borrows another connection, with its own transaction; SQLite permits only one writer per file. Fibers started inside a callback do not inherit its loan.

Only model calls follow the transaction. A query builder call on the database itself, `$database->table(...)`, still borrows another connection and runs outside it; inside a transaction use the connection the callback is given, or `$database->current()`.

When a transaction is rolled back, the models saved or deleted in it are again what the database holds: a new model is new, with its generated key emptied, and a stored one has the values it had. So the same callback can be run again, after a deadlock for one, and writes them again, with the times of the attempt that commits. The properties keep what you assigned; what Tide filled in, a generated key or a kept time, goes back to what it was. When a commit fails without saying whether it happened, as when the connection is lost while the database answers, the models are left as stored: check the row before saving them again.

## JSON and limits

```php
// json.php
$json = json_encode(Book::query()->with('author')->find(1), JSON_THROW_ON_ERROR);
```

JSON includes initialized columns and loaded relations in declaration order, without `#[Hidden]` properties. Hidden getters and unmapped virtual getters are never evaluated; no public property name is reserved. Dates use ATOM text unless the model overrides `serializeDate(\DateTimeInterface $date): string`, as an API that gives times as the database holds them does; enums, arrays and nested models are handled by `json_encode()`. Avoid serializing a cycle of loaded relations.

A `#[Json]` array is compared as PHP compares arrays. An object placed inside one and changed in place is not seen as a change; assign the array again.

Tide does not load on property access, maintain an identity map, validate input, soft-delete, fire model events or provide collections, many-to-many polymorphic relations or composite keys. Models belong to one request; give a job an id and read the row in the job.

See [CONTRIBUTING.md](CONTRIBUTING.md) for checks and [BENCHMARKS.md](BENCHMARKS.md) for measured reading costs.

# Tide

Tide adds typed models and explicit relations to the [Crab framework](https://github.com/crabphp/framework). New to it? Start with the [guide by use case](docs/guide.md); this README is the reference. Use a model for a whole row you read, change and save. Use the framework's query builder for arrays, and `into()` records for projections and reports.

Tide is unreleased. It requires PHP 8.4 and the framework changes in this workspace; the published framework 0.6 releases do not yet include them. Install the local package with Composer's path repository during development. When available as a release, the package is `crabphp/tide`:

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

Conditions and ordering use the query builder's parameters, except that a closure given to `where()` or `orWhere()` receives a `ModelQuery`, with its conditions grouped. It can combine `whereHas()`, `whereDoesntHave()` and ordinary conditions. Builder grouping through `tap()` still receives the framework's `Query`. Inside such a closure the query can only be given conditions: running, cloning or tapping it there is refused, since the conditions being written are not yet part of it. A condition the model query has no method for is written with `whereRaw('quantity * price >= ?', [100])` or `orWhereRaw()`, there and anywhere else; every value is a binding. A query returns a PHP list of models, and `first()` or `find()` returns a model or null:

```php
// reading.php
$books = Book::query()->where('title', 'like', '%PHP%')->orderBy('id')->get();
$book = Book::find(1);
$page = Book::query()->orderBy('id')->paginate(20);
$names = Author::query()->orderBy('id')->pluck('name');
```

`simplePaginate()`, `cursorPaginate()` and `chunkById()` also return models. Cursor pagination and chunking default to the declared key and require one when their column is omitted. `chunkById()` reads in ascending order of that column and refuses an existing order; remove `orderBy()` before calling it. Aggregates, `value()` and `pluck()` return plain database values. Query execution leaves the query unchanged; clone it to branch. `when()` receives the model query. `tap()` receives the framework's `Query` for operations not forwarded; a partial selection fails when it cannot make a whole model row.

## Relations

Declare a relation outside the constructor with no default. Reading it before loading is PHP's uninitialized-property error. `isset()` is false both for an unloaded relation and for a loaded null relation.

```php
// relations.php
use Crab\Tide\ModelQuery;

$books = Book::query()->with('author')->orderBy('id')->get();
Book::load($books, 'author');
$authors = Author::query()->with('books', static fn (ModelQuery $query): ModelQuery => $query->orderByDesc('id'))->get();
$recent = Author::find(1)->related('books')->orderByDesc('id')->limit(5)->get();
$withBooks = Author::query()->whereHas('books')->get();
$withoutBooks = Author::query()->whereDoesntHave('books')->get();
$grouped = Book::query()->where(static fn (ModelQuery $query): ModelQuery => $query->whereHas('author')->orWhere('title', 'PHP'))->get();
```

A property that is another model belongs to it with no attribute. `HasOne` declares a nullable or required model property. `HasMany` declares an array and names its related class, as `BelongsToMany` does. Each takes the names it would otherwise assume: `BelongsTo` a foreign-key column on this model and an optional owner-key column; `HasOne` and `HasMany` a related foreign-key column and an optional local-key column; `BelongsToMany` the pivot table and its two foreign-key columns. Omitted owner and local keys use the model's key.

A dotted path such as `books.author` loads through relations. A closure after a path constrains its last part. Loading uses one query per relation, or one per batch of 1,000 distinct keys. Related objects are shared within that load, and loading again replaces the property. Missing single rows become null only on nullable properties; many relations become empty arrays. With `with()` and `load()`, lists default to ascending related key order, and `HasOne` takes the first row in that order. `HasOne` and `BelongsTo` hydrate only the winning row for each parent key; the database still sends all matching rows. A `related()` query has only the order you give it: `related('one')->first()` without an order may return any matching row.

On a many-to-many relation, a condition or an order on a plain column name means the related model's column, whatever the pivot table calls its own. A `related()` query keeps its parent condition permanently: later model conditions are grouped within that parent, so an `orWhere()` cannot reach another parent's rows. Order, limit and `with()` still apply to the query.

A limit or offset in an eager constraint is refused; use `related()` for one model's limited query. A relation in another database reads that database's current connection, with a many-to-many pivot beside the related table. `whereHas()` and `whereDoesntHave()` cannot cross databases. `tap()` changes are not tracked for eager limit and order checks.

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

A model that declares `created_at` and `updated_at` as dates has them kept, in PHP's default time zone, the one they are read back in: both when it is first saved, and `updated_at` at each change, bulk `update()`, `increment()` and `decrement()` included. A time you set yourself is written as you set it, and a save that changes nothing writes nothing. The time is the application's clock, to the second.

`saveMany()` saves several models of one class, new or stored, in one transaction, so that all are written or none is, and returns them with their keys and times:

```php
// save-many.php
[$ada, $grace] = Author::saveMany([new Author('Ada'), new Author('Grace')]);
```

It is `save()` for each, one statement apiece. Inside a transaction already, it is a savepoint of it.

Many new models of one class are inserted together with `insertMany()`, which returns how many rows it inserted:

```php
// insert-many.php
$drafts = [new Book('First', $author->id), new Book('Second', $author->id)];
$count = Book::insertMany($drafts);   // 2
```

Each is written as `save()` writes it. A model whose key you gave is stored afterwards. One whose key the database numbers stays new, without its key, since a database tells only the first of several; read the rows back where you need them. Thousands of rows are sent in parts of 500.

A null property is left out of the insert, so the database gives the column its default, or null when it has none. The model learns what the database filled when you `refresh()` it. On a stored model null is a value like any other, and assigning it writes null.

Changing a foreign key and saving unsets its loaded `BelongsTo` property. Assigning a relation itself changes no foreign key. Arrays and scalars compare strictly, dates by their instant, and cast objects by their written value, including mutations inside them. Mutable `DateTime` values held by `DateTimeInterface` or `mixed` columns are snapshotted as immutable copies, so in-place date changes are detected. A failed statement leaves the snapshot untouched so you can correct the model and save again.

Deletes and updates use the stored key, even if you changed the key property. Delete makes the model new again and keeps loaded relations. Refresh reads into the same object, unloads relations and throws `RowGone` if the row disappeared. Clone keeps the original snapshot and still represents the same row. Bulk `update()`, `increment()`, `decrement()` and `delete()` operate directly on the database and do not change models already held. Every written column must be declared by the model, including the increment/decrement column and extra values; extras use the same field conversion as `update()`. Use the query builder to write other columns.

Bulk writes with `whereHas()` or `whereDoesntHave()` reaching the target model's own table, through a path or a constraint too, are refused on every driver. Read the matching keys first, then write with `whereIn()`. Reads with those relation conditions are supported.

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

Tide does not load on property access, maintain an identity map, validate input, soft-delete, fire model events or provide collections, polymorphic relations or composite keys. Models belong to one request; give a job an id and read the row in the job.

See [CONTRIBUTING.md](CONTRIBUTING.md) for checks and [BENCHMARKS.md](BENCHMARKS.md) for measured reading costs.

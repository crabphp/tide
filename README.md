# Tide

Tide adds typed models and explicit relations to the [Crab framework](https://github.com/crabphp/framework). Use a model for a whole row you read, change and save. Use the framework's query builder for arrays, and `into()` records for projections and reports.

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

The plugin resolves the application's database on first use. In a standalone test, give Tide your database:

```php
// test-setup.php
use Crab\Tide\Tide;

Tide::use($database);
```

## Models

Name the table and declare its columns. A constructor is for making a new model; reading a row never calls it. The database supplies an integer key when you leave it uninitialized. Supply a string key yourself.

```php
// models.php
use Crab\Tide\BelongsTo;
use Crab\Tide\HasMany;
use Crab\Tide\Hidden;
use Crab\Tide\Model;
use Crab\Tide\Table;

#[Table('authors')]
final class Author extends Model
{
    public int $id;

    #[HasMany(Book::class, 'author_id')]
    public array $books;

    public function __construct(public string $name) {}
}

#[Table('books')]
final class Book extends Model
{
    public int $id;

    #[Hidden]
    public string $internal_note;

    #[BelongsTo('author_id')]
    public ?Author $author;

    public function __construct(public string $title, public ?int $author_id) {}
}
```

Public, backed properties are columns; private, protected, static and virtual properties are not. Columns accept the framework's record types and casts, including backed enums, `DateTimeImmutable`, `#[Json]` arrays and custom `Cast` attributes. `#[ColumnName('created_at')]` maps another column name. Hydration bypasses a column's set hook; application assignments still run it. Columns and relations must be writable.

`#[Table('books', key: 'uuid', database: 'reports')]` names another key column and a configured database. Table, pivot and key names are identifiers, not SQL. A model without a key can be read, but cannot be found by key, saved, deleted, refreshed or queried through `related()`.

## Reading

Conditions and ordering use the query builder's parameters. A query returns a PHP list of models, and `first()` or `find()` returns a model or null:

```php
// reading.php
$books = Book::query()->where('title', 'like', '%PHP%')->orderBy('id')->get();
$book = Book::find(1);
$page = Book::query()->orderBy('id')->paginate(20);
$names = Author::query()->orderBy('id')->pluck('name');
```

`simplePaginate()`, `cursorPaginate()` and `chunkById()` also return models. Aggregates, `value()` and `pluck()` return plain database values. Query execution leaves the query unchanged; clone it to branch. `when()` receives the model query. `tap()` receives the framework's `Query` for operations not forwarded; a partial selection fails when it cannot make a whole model row.

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
```

`HasOne` declares a nullable or required model property. `HasMany` declares an array and names its related class. `BelongsToMany` names that class, the pivot table, and its two foreign-key columns. `BelongsTo` takes a foreign-key column on this model and an optional owner-key column; `HasOne` and `HasMany` take a related foreign-key column and an optional local-key column. Omitted owner and local keys use the model's key.

A dotted path such as `books.author` loads through relations. A closure after a path constrains its last part. Loading uses one query per relation, or one per batch of 1,000 distinct keys. Related objects are shared within that load, and loading again replaces the property. Missing single rows become null only on nullable properties; many relations become empty arrays. Lists default to ascending related key order, and `HasOne` takes the first row in that order.

A limit or offset in an eager constraint is refused; use `related()` for one model's limited query. A relation in another database reads that database's current connection, with a many-to-many pivot beside the related table. `whereHas()` and `whereDoesntHave()` cannot cross databases. `tap()` changes are not tracked for eager limit and order checks.

## Writing

Make a model with `new`, then save it. Later saves write only changed columns. Columns filled by database defaults remain uninitialized until you refresh:

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

Declare a column the database fills, such as `created_at` with a default of the current time, without a default of its own: `public ?\DateTimeImmutable $created_at;`. Written `= null`, the property has a value, and the insert writes null in place of the database's default.

Changing a foreign key and saving unsets its loaded `BelongsTo` property. Assigning a relation itself changes no foreign key. Arrays and scalars compare strictly, dates by their instant, and cast objects by their written value, including mutations inside them. A failed statement leaves the snapshot untouched so you can correct the model and save again.

Deletes and updates use the stored key, even if you changed the key property. Delete makes the model new again and keeps loaded relations. Refresh reads into the same object, unloads relations and throws `RowGone` if the row disappeared. Clone keeps the original snapshot and still represents the same row. Bulk `update()`, `increment()`, `decrement()` and `delete()` operate directly on the database and do not change models already held.

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

A nested transaction on the callback's connection uses a savepoint. A nested call on the database borrows another connection, with its own transaction; SQLite permits only one writer per file. Fibers started inside a callback do not inherit its loan. Discard models written in a transaction that rolled back: the objects still remember the successful statements.

## JSON and limits

```php
// json.php
$json = json_encode(Book::query()->with('author')->find(1), JSON_THROW_ON_ERROR);
```

JSON includes initialized columns and loaded relations in declaration order, without `#[Hidden]` properties. Dates use ATOM text; enums, arrays and nested models are handled by `json_encode()`. Avoid serializing a cycle of loaded relations.

Tide does not infer names, load on property access, maintain an identity map, validate input, fill timestamps, soft-delete, fire model events or provide collections, polymorphic relations or composite keys. Models belong to one request; give a job an id and read the row in the job.

See [CONTRIBUTING.md](CONTRIBUTING.md) for checks and [BENCHMARKS.md](BENCHMARKS.md) for measured reading costs.

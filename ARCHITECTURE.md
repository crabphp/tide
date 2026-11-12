# Tide architecture

Status: **released as 0.1.0, 2026-11-12.** The framework's own design is in [../framework/ARCHITECTURE.md](../framework/ARCHITECTURE.md), and this document follows its goals, layers and scopes.

## What it is

Tide (`crabphp/tide`) is a package that adds models to Crab: a class per table, whose objects are read, changed and saved, and which knows its related rows.

```php
final class Book extends Model
{
    public int $id;
    public ?\DateTimeImmutable $created_at = null;
    public ?\DateTimeImmutable $updated_at = null;

    public ?Author $author;

    /** @var list<Chapter> */
    #[HasMany(Chapter::class)]
    public array $chapters;

    public function __construct(
        public string $title,
        public int $author_id,
        public BookStatus $status = BookStatus::Draft,
        public ?\DateTimeImmutable $published_at = null,
    ) {}

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
```

```php
$books = Book::query()->where('status', BookStatus::Published)->with('author', 'chapters')->latest('published_at')->get();
$book  = Book::find(7);

$book = new Book(title: 'Timeline Taxi', author_id: $author->id);
$book->save();

$book->title = 'A new title';
$book->save();                                              // writes the title, nothing else

$recent = $author->related('books')->latest('id')->limit(5)->get();
```

The shape of a model is Tempest's: typed properties are the columns, relations are typed properties, attributes say the rest. The calls are Eloquent's, which are also the query builder's own: `where`, `with`, `get`, `first`, `find`, `paginate`, `save`, `delete`.

## How it fits Crab

Decision 6 of the framework stays as written: the framework has no model layer, and an application that wants one brings a library. This is that library. It is opt-in, listed in `app.php`, and absent from the skeleton.

| Framework goal | What it means here |
| --- | --- |
| Lean | One base class, one query class, four relation attributes and JSON hiding. The names it assumes come from three small rules, not an inflector's dictionary. No collection class, no events. A model costs nothing an application does not use. |
| Long-running by design | A model holds no connection and no shared state. There is no identity map, no registry of models and nothing booted. What is cached is read from code alone, so one copy serves every worker and request. |
| Async drivers | A query is a place where the worker may serve another request. So a query only ever runs at a call that looks like one: `get()`, `find()`, `save()`. Reading a property never runs a query. |
| Measured | Reading rows into models is measured against arrays and against records, with the JIT off, and the numbers are kept in `BENCHMARKS.md`. |
| Readable by people and agents | Every column is a typed property. There is no magic property access or method dispatch: every call on a model or a model query has a real signature. A name Tide assumes follows one written rule, and an attribute beside the property says it where it differs. |
| One way to do each thing | A model is made with `new`. A query starts at `query()`. Relations are loaded explicitly with `with()` or `Model::load()`. There is no second spelling of any of them. |

### Three ways to read a table

The package adds the third. Each has one job, described in the package README.

| | Use it for | Gives |
| --- | --- | --- |
| Query builder | A statement with no class behind it: a report, a bulk change, a table with no model | Arrays |
| Record, `into()` | The typed shape of one query's result: a summary, a projection, a row of a report | A readonly object |
| Model | A row the application reads, changes and saves, and its related rows | A model |

A model's types describe its whole row; a query decides which of its columns it reads. One that reads columns of several tables, or expressions, is read into a record.

## Layers

The package is in the framework's top layer, beside `crabphp/redis` and `crabphp/s3`: it depends on `Crab\Contracts` and PSR interfaces, plus the `Crab\Database\ColumnName` attribute. It names no other database component class in its source. A model query is built on `Contracts\Database\Query`, so everything the builder writes, for MySQL and for SQLite, is the builder's.

```
tide/
  src/
    Tide.php             holds the application's database for model calls
    Model.php            the base class: query(), find(), save(), delete(), refresh(), related(), load()
    ModelQuery.php       conditions and order, forwarded by name; with(), whereHas(); get(), first(), paginate()
    ModelMap.php         a model class as its table, key, columns and relations, read once
    Relations.php        loads relations for a list of models, one query per relation
    Table.php            #[Table]
    BelongsTo.php  HasOne.php  HasMany.php  BelongsToMany.php
    Hidden.php           #[Hidden]
    TidePlugin.php
  tests/
```

### What the framework gains

Five changes, each small and useful without this package.

1. **`Database::current()`**, in the contract: the connection the calling fiber was lent by `transaction()` or `run()`, and the database itself when there is none. See [Connections and transactions](#connections-and-transactions).
2. **`#[Json]` and `#[ColumnName]` on a property.** Both now accept a property as well as a constructor parameter.
3. **Reading and writing one value by its type**, behind a contract. `Contracts\Database\Field` now owns conversion, and `RecordMap` reads record declarations. A date, an enum and a bool must cross the same way for a model as for a record, so there is one implementation, the framework's.
4. **A backed enum in a condition.** The drivers write a date given to `where()` as text and a backed enum as its value, so that `where('status', OrderStatus::Shipped)` reads as it should, with or without models.
5. **`Connection::afterRollback()`**, the opposite of `afterCommit()`: it runs if the transaction that asked, or one around it, is rolled back or loses its connection. A model uses it to forget a write that did not happen.

## The model

A model is a concrete class that extends `Crab\Tide\Model`. Nothing else is required of it.

**What a model leaves unsaid is named after its classes.** This is where Tide departs from the framework's rule that nothing is switched by a naming convention, by the owner's decision of 2026-11-11: a model that only extends `Model` should work, as one does in Laravel. Each assumed name follows one rule written in the tables below, an attribute overrides it, and a wrong one fails loudly, as the database's error naming the table or as Tide's naming the missing column.

**It is a base class, not a trait,** which is where this differs from Tempest. A response is written with `json_encode()`, which writes every public property of a plain object. Only a class that implements `JsonSerializable` can leave a `#[Hidden]` property out, and a trait cannot implement an interface. With a trait, forgetting two words on a class would send a password hash. A base class also gives the mapper a type to refuse everything else by.

**A model is made with `new`.** What a new row must have is a constructor parameter, checked by PHP and by static analysis. What the database gives, `id` and columns with a default there, is a property outside the constructor. There is no `create()`. Reading a row never calls the constructor.

### Columns

| Case | Rule |
| --- | --- |
| What is a column | Every public, typed, non-static property that has no relation attribute, promoted or not |
| What is not | A protected or private property, and a virtual property (a hook with no value of its own), which is how a computed value is written |
| A backed property with hooks | Persistence reads and writes raw values, including keys and snapshots, without running hooks. Application reads and writes run their hooks; JSON reads through getters |
| Asymmetric setter visibility | Column properties with `public private(set)` or `public protected(set)` are assigned through cached reflection metadata |
| The column's name | The property's name. `#[ColumnName]` gives another. Nothing is converted between snake and camel case |
| Types | Those of a record: `int`, `float`, `string`, `bool`, a backed enum, `DateTimeImmutable`, an array with `#[Json]`, anything else with a `Cast` |
| Refused | No type, a union type, `readonly`, `DateTime`, an array without `#[Json]`, two properties on one column. Said on first use, naming the property and what to write instead |
| The table | The class in snake case and plural, by `s`, `es` or `ies`: `Book` reads `books`, `OrderItem` reads `order_items`, `Category` reads `categories`. `#[Table('people')]` names another |
| The key | `id`, or `#[Table(key: 'uuid')]`. One column, an `int` or a `string`. A model with no such property can be read but not found, saved or deleted |
| Another database | `#[Table('totals', database: 'reports')]`, a name from `database.connections` |
| Parents | A model may extend an abstract model, whose properties are its own. Several classes in one table are not supported |
| A column the table lacks | The database's error, which names it. Nothing checks a model against its table beforehand: the database is the source of truth |
| A column the model lacks | Never read and never written |
| A value that does not fit | Null in a property that is not nullable, a value the enum does not have, text in an `int`: refused, naming the property and the column, never the value |

### State

A model keeps one thing of its own: the values it was read with, or null while it is new. That is how `save()` knows whether to insert, and what changed. The snapshot is keyed by property name and holds raw property values. A mutable `DateTime` is copied to `DateTimeImmutable`, including when held by a `DateTimeInterface` or `mixed` column. For a cast whose value is an object it instead holds the written column value, so in-place mutation is detected. Database-filled columns left uninitialized by an insert are absent until they are read or assigned.

| Case | Rule |
| --- | --- |
| The same row read twice | Two objects. Changing one does not change the other |
| A model kept in a service or a static | Not allowed, as for anything of one request |
| `clone` | A second object for the same row |
| A model given to a job | Give its id. A job runs later, in another process, and reads the row as it is then |
| As JSON | Its columns, a loaded relation under its name, an unloaded one left out, a `#[Hidden]` property never |

## Queries

`Book::query()` returns a `ModelQuery<Book>`: it chooses rows of its model and returns models. It has, each as a real method, the `where` family, `whereHas()` and `whereDoesntHave()`, `orderBy`, `limit`, `offset`, `lockForUpdate` and `with()`, and ends with `get`, `first`, `find`, `exists`, `count`, `sum`, `avg`, `min`, `max`, `pluck`, `value`, `paginate`, `simplePaginate`, `cursorPaginate` and `chunkById`. The totals and values read only the model's own columns and give them as its properties hold them. `Book::find($id)` is `Book::query()->find($id)`.

Tide does the ORM's job only: it maps rows and relations and saves models, and does what the model's types can say. What they cannot, it leaves to the query builder: a join, an expression, a raw condition, and writes to many rows. Those start on the database, `$db->current()->table('books')`, with an `into()` record for a typed shape. There is no handoff from a model query to the builder.

| Case | Rule |
| --- | --- |
| Columns | Every declared column is named, never `*`. `select('title')` reads those, `except('body')` every other; the rest are uninitialised, never a default or null: reading one fails, JSON leaves it out, `save()` never writes it unless assigned, `refresh()` reads the whole row |
| Columns always read | The key; the local keys of the query's relations and the foreign keys of a relation's own query; a cursor page's order columns; a column with a hook and a default |
| Grouped conditions | Closures given to `where()` and `orWhere()` receive `ModelQuery`, so ordinary and relation predicates compose. The query cannot be run or copied inside one |
| A condition on related rows | `whereHas('chapters', fn ($query) => ...)` and `whereDoesntHave()`, written as `EXISTS`, so a model is never repeated for each related row |
| A value in a condition | Conditions pass values to the drivers: enums become backing values and dates become text |
| Cursor and chunk defaults | The declared key when the unique/cursor column is omitted; refused without a key. Chunking refuses an existing order |
| A page or a chunk with `with()` | Relations are loaded for each page and each chunk |
| A reusable condition | A static method on the model that takes and returns the model query |
| Rows SQL chooses | The builder selects their ids and `whereIn('id', $ids)` reads them as models |

## Relations

A relation is a plain public property with an attribute. Hooks and asymmetric setter visibility are refused when the model is mapped, so loading and unloading are supported. The class is the property's type, or named in the attribute where the type is `array`. A property whose type is another model belongs to it with no attribute at all. The keys are named after the models unless the attribute names them.

```php
public ?Author $author;                               // belongs to: author_id, the property's name
#[HasOne] public ?Isbn $isbn;                         // isbns.book_id, this model's name
#[HasMany(Chapter::class)] public array $chapters;    // chapters.book_id
#[BelongsToMany(Tag::class)] public array $tags;      // pivot book_tag, both names in alphabetical order, with book_id and tag_id

#[BelongsTo('written_by')] public ?Author $author;    // each name can be said instead
#[HasMany(Chapter::class, 'volume_id')] public array $chapters;
#[BelongsToMany(Tag::class, pivot: 'taggings', key: 'item_id', relatedKey: 'tag_id')] public array $tags;
```

There are three ways to reach related rows, and each is a call.

```php
Book::query()->with('author', 'chapters.notes')->get();     // with the models, one query per relation
Book::load($books, 'author');                               // for models already held
$author->related('books')->latest('id')->limit(5)->get();   // as a query of its own
```

**Nothing is loaded by reading a property.** A relation property has no default, so until it is loaded it is uninitialised: reading it is PHP's own error, and `isset($book->author)` is false while unloaded and also when the loaded value is null.

| Case | Rule |
| --- | --- |
| No models | No query |
| A null foreign key, or no row for it | Null when the property is nullable. Otherwise refused, naming the relation |
| The same key many times | Asked for once |
| Very many keys | Asked for 1,000 at a time |
| Matching rows to models | By PHP array key, on `int` and `string` values: `7` and `"7"` are one key. Two keys the database's collation calls equal and PHP does not are not matched |
| `HasOne` with several rows through `with()` or `load()` | The first in the requested order, the lowest key by default. Losing rows are skipped before hydration, as for `BelongsTo`; matching rows are still transferred from the database |
| `HasMany` order through `with()` or `load()` | By the related key, unless the condition orders |
| `related()` order | Only the order explicitly given. `related('one')->first()` without an order may return any matching row |
| `related()` conditions | The parent condition is permanent; later model predicates are grouped within it, so OR cannot reach another parent. Order, limit and `with()` still apply |
| A condition, `with('chapters', fn ($query) => ...)` | Allowed. A `limit` in it is refused: it would cut the whole list, not each model's. Use `related()` |
| One related row for many models | One object, shared within that load |
| A name that is no relation | Refused, listing the model's relations |
| `BelongsToMany` | One query that joins the pivot. Its parent key is selected as `tide_pivot`, with `_` appended until the alias does not collide with a model column. The pivot's other columns are not read; a pivot with data of its own is a model |
| A related model in another database | Read from its own. `BelongsToMany` needs the pivot beside the related table |
| Assigning a relation property | Sets the property and nothing else. The foreign key is its own column and the only thing written |
| Saving a changed foreign key | The relation loaded for the old key is unset, so that reading it fails instead of answering with the old row |
| `related()` on a model not yet saved | Refused |
| `#[MorphTo]` | Its union type lists the models it may belong to; the type column holds one's morph name and the id column its key, named after the property unless said. The morph name is the table's, or `#[Table(morph: ...)]`, so a class can be renamed without changing rows. Two listed models with one morph name are refused |
| Loading a `MorphTo` | Grouped by type, one query per model per batch of keys. A type the union does not list is refused, naming it. A nested path past it must be a relation of each listed model. `related()` and `whereHas()` cannot follow it |
| `#[MorphMany]`, `#[MorphOne]` | Paired with the related model's `MorphTo` that lists this model, or the one `relation:` names. Every read, `with()`, `related()` and `whereHas()`, keeps to rows whose type column holds this model's morph name, so a video's comment 7 never reaches post 7 |
| `#[MorphToMany]`, `#[MorphedByMany]` | A `BelongsToMany` whose pivot has a type column: the name gives the pivot, plural, and its `_type` and `_id` columns. `MorphToMany` holds its own model's morph name there, `MorphedByMany` the related model's. Loading, `whereHas()` and the totals add the type condition; `related()` adds it to its parent condition, so no `orWhere()` escapes it |
| `attach()`, `detach()`, `sync()` | The links of any many-to-many relation, by the related models' keys, in one transaction or a savepoint. `attach()` leaves a link already there, `detach()` removes only the given, `sync()` locks the model's row and makes the links exactly the given. Refused for another kind of relation, a new model, keys of another type, and a pivot in another database. The relation is unloaded afterwards |
| `associate()` | Points a `BelongsTo` or `MorphTo` at a stored model, or at none with null: sets its key column, and a morph's type column, and the relation. `save()` writes them. Saving a changed type or id unsets the loaded `MorphTo` |

## Writing

| Case | Rule |
| --- | --- |
| `save()` on a new model | An insert of the properties that have a value. The rest are left to the database's defaults |
| The key the database makes | Set on the model, for an `int` key. A `string` key is given before saving |
| Columns the database fills | Uninitialised after the insert. `refresh()` reads them; nothing reads them unasked |
| A property on insert | Uninitialised, it is left out and the database gives its default; `refresh()` reads what it filled. Null, assigned or a `= null` default, is written as NULL, so the snapshot is what the row holds |
| A hooked key or kept time | Refused, before anything is written, when Tide would fill it in: a database-numbered key, a time stamped into an empty property. PHP cannot make a hooked property uninitialised again on rollback |
| A new model with no value at all | Refused |
| `save()` on a read model | An update of the columns that changed, by the key it was read with. No change, no statement |
| What counts as changed | A value that differs from the one read: scalars and arrays strictly, a date by its instant, an object of a `Cast` by what it writes, which sees a change made inside it |
| An update that changes no row | Not an error. MySQL counts changed rows, so none may mean the row is gone or the values were already so |
| Two requests, different columns | Both are kept, since each writes only what it changed |
| Two requests, one column | The last wins. Read with `lockForUpdate()` in a transaction where that matters |
| A save the database refuses | The model is as it was, and can be corrected and saved again |
| `delete()` | By key. The model is new again. Deleting a new model is refused. What is deleted with it is for foreign keys to say |
| `refresh()` | Reads the row again and unloads its relations. Refused when the row is gone |
| `created_at`, `updated_at` | Kept when the model declares them as dates: both at the first save, `updated_at` at each change. A write to many rows is the query builder's and keeps no times. A time the application set is written as set; a save that changes nothing writes nothing. The time is the application's clock, to the second. `#[Timestamps]` names other columns, or null for one kept by hand |

## Connections and transactions

A model call names no connection, so there must be one place that decides it. This is the only ambient thing in the package, and it is small on purpose.

`TidePlugin` gives `Tide::use()` a closure that resolves the application's `Database` on first use, keeping the resolved result; a test calls `Tide::use($database)` itself. That object is process scope: it is defined once, is safe to make anywhere, and opens its connections in each worker. A static name for a process-scope thing is what `Route` and `config()` already are.

Every statement of a model then runs on `current()` of its database. A `ModelQuery` retains both that database and the connection it started on. Before a statement or relation load, when the connection is not the database itself, it must still equal that database's `current()`; otherwise it throws `LogicException` saying to start the query where it runs. This checks the innermost current connection by identity, without a separate lease token.

| Case | Rule |
| --- | --- |
| Inside `$db->transaction()` | On the transaction's connection, with no `$tx` to pass and none to forget |
| The queue and the broadcaster in a transaction | They still take the transaction, `within($transaction)`, so a callback that pushes a job or sends an update keeps its parameter |
| A transaction inside a transaction | Calling the database again borrows another connection and restores the outer loan on return. Calling the lent connection uses a savepoint on that same connection. SQLite allows one writer per file |
| Inside `$db->run()` | On the connection it lent, and so in a transaction begun on that connection |
| A connection taken with `acquire()` by hand | Not current. Use `run()` |
| A query started outside any loan and run inside a transaction | On the pool it started on. Start the query where it runs |
| A query captured in a loan, run after it or while another loan is innermost | Refused unless its captured connection is still that database's current connection |
| A task started inside a transaction | On the pool. One connection cannot run two statements at once |
| A transaction that lost its session | Model calls fail until its callback has unwound. They never fall back to the pool |
| Outside a fiber, in a command or a test | One place stands for the main context |
| A fiber that ends mid-transaction | Kept by weak reference, so nothing is left behind |
| A model of another database | Current is kept for each database. No transaction spans two |
| A save or a delete, and then the transaction is rolled back | The model is again what the database holds: a new one is new, its generated key emptied, and a stored one has the values it had. Running the callback again, after a deadlock for one, writes it again. An inner transaction's rollback undoes only what was written in it |
| A query builder call on the database inside a transaction | Outside it, on another connection, as the framework has it. Only model calls follow the transaction; use the callback's connection or `current()` for the builder |
| No `TidePlugin` | Refused on the first call, saying to list it |

**Why ambient, in a framework with no hidden globals.** The other design passes the connection: `Book::query($tx)`, `$book->save($tx)`. Leaving it out would then run the statement outside the transaction, silently, and be found in production. A mistake that cannot be seen is worse for a reader, and for an agent, than one rule that is always true: a model call is in the transaction it is written in. The state is kept for the length of the transaction's callback and cleared as it returns, so it is bound to the code a reader is looking at.

An injected service in place of static calls, `$this->models->find(Book::class, 7)`, was considered. It names its dependency, which Crab otherwise prefers. It is not proposed, because it puts a second object in every call and has the same transaction question.

## Measured

Before `save()` was built, `benchmarks/read.php` measured 1, 100 and 10,000 rows as arrays, as records and as models, with the JIT off, and loads one relation for each. At 10,000 rows, model reads took about 1.27 times record reads, including the stored-value snapshot. The decision was to proceed; [BENCHMARKS.md](BENCHMARKS.md) records the environment, medians and limitations.

## Tests

The suite covers mapping, persistence, query forwarding, relations, transactions and the reproduced review regressions. Driver-dependent cases run on SQLite and, when configured, PDO MySQL and async MySQL. These tests exercise selected scenarios and combinations; they do not establish every possible combination of the rules above. The README's examples are run by a `ReadmeTest`, as in the other packages.

## Build order

1. The four framework changes, each with its tests and its page of the guide.
2. Reading: `ModelMap`, `query()`, `find()`, the model query, JSON and `#[Hidden]`.
3. Relations: `with()`, `load()`, `related()`, `whereHas()`.
4. The measurement. A decision to go on.
5. Writing: `save()`, `delete()`, `refresh()`.
6. The package README, executable examples and contributor guide. The framework guide remains independent of the package; its guide is published beside the framework's on the website.

## Not planned

- Loading a relation by reading a property.
- Model events, observers and global scopes.
- A collection class. A list of models is a PHP list.
- Soft deletes.
- A dictionary of irregular plurals. `#[Table]` names the table of a word English makes plural another way.
- Validation rules on a model. A request is validated by its action.
- Relations through another model, keys of several columns.
- A generator, `make:model`, and a check of models against the schema.

## Settled choices

- The five framework changes are implemented; `Contracts\Database\Field` is shared by records and models.
- The database's own statements still borrow from its pool. `current()` exposes the current loan without changing existing callers.
- Names are assumed from the classes and times are kept, both since 2026-11-11, by the owner's decision; attributes say the exceptions.
- Relations are writable properties with no defaults. Readonly relation properties are refused because loading again and unloading after a changed foreign key must work.
- The framework's no-ORM scope stays unchanged; Tide is an optional package. The sample application runs on it.

# Tide by use case

Tide gives Crab models: a class per table, whose objects you read, change and save. This guide goes through what an application does with them, with the [sample shop](https://github.com/crabphp/sample)'s own models as examples. The [README](../README.md) is the reference, with every rule; its examples are run by the tests.

Tide is a separate package, not yet released: it needs the framework's `main` branch, and is installed from [its repository](https://github.com/crabphp/tide) for now.

- [Setting up](#setting-up)
- [Declaring a model](#declaring-a-model)
- [Reading](#reading)
- [Relations](#relations)
- [Writing](#writing)
- [Transactions](#transactions)
- [JSON for APIs](#json-for-apis)
- [When not to use a model](#when-not-to-use-a-model)
- [What is not there](#what-is-not-there)

## Setting up

List the plugin in `app.php`. It uses the application's database and clock:

```php
return App::configure(__DIR__)
    ->withPlugins([TidePlugin::class])
    ->create();
```

A test on its own gives them with `Tide::use($db, $clock)`; the clock is optional.

## Declaring a model

A model extends `Model`, and its typed public properties are its columns:

```php
final class Product extends Model
{
    public int $id;
    public string $sku;
    public string $name;
    public int $price;                        // in cents
    public int $stock = 0;
    #[Json] public ?array $details = null;    // a JSON column, decoded
    public ?\DateTimeImmutable $created_at;
    public ?\DateTimeImmutable $updated_at;

    public function inStock(): bool
    {
        return $this->stock > 0;
    }
}
```

What a model leaves unsaid is named after its class, and an attribute says it where it differs:

| Assumed | Where it differs |
| --- | --- |
| The table from the class: `Product` reads `products`, `OrderItem` reads `order_items` | `#[Table('people')]` |
| The key is `id` | `#[Table(key: 'uuid')]` |
| A column is named as its property | `#[ColumnName('email_address')]` |
| `created_at` and `updated_at`, when declared as dates, are kept by Tide | `#[Timestamps(updated: null)]` |

A property is an `int`, `float`, `string`, `bool`, a backed enum (stored as its value), a `DateTimeImmutable`, an array marked `#[Json]`, or anything with a `Cast` attribute of your own. Methods are plain PHP. A computed value is a property hook, which Tide never stores. `#[Hidden]` keeps a column, a password hash for one, out of JSON.

## Reading

```php
$product  = Product::find(7);                                                        // ?Product
$products = Product::query()->where('category', 'kitchen')->orderBy('name')->get();   // list<Product>
$page     = Product::query()->latest('id')->paginate(20, $pageNumber);
$taken    = Account::query()->where('email', $email)->exists();
$names    = Product::query()->pluck('name', 'id');                                   // [id => name]
```

The conditions are the query builder's, `where`, `whereIn`, `whereBetween`, `whereNull` and the rest, with `whereRaw('quantity * price >= ?', [100])` for what has no method, its values always bound. An enum or a date is given as it is: `where('status', OrderStatus::Paid)`.

A list is a PHP array of models. `paginate()`, `simplePaginate()` and `cursorPaginate()` give a `Page` whose `items` are models, and `chunkById()` walks a large table.

A query used in several places is a static method of the model. The sample's catalogue, home page, admin list and API all read through one:

```php
public static function search(ProductSearch $search): ModelQuery
{
    return self::query()
        ->when($search->q !== '', fn (ModelQuery $q) => $q->where('name', 'like', "%{$search->q}%"))
        ->when($search->category !== null, fn (ModelQuery $q) => $q->where('category', $search->category))
        ->orderBy('name');
}

$page = Product::search($search)->paginate($search->perPage, $search->page);
```

A model is always a whole row. A report or a projection, counts, sums, a few columns of several tables, is the query builder's or an `into()` record's; the sample's daily report and category counts are.

## Relations

```php
final class Order extends Model
{
    public int $user_id;

    #[HasMany(OrderItem::class)]              // order_items.order_id
    public array $lines;

    #[BelongsTo('user_id')]                    // named for what it is here, so its key is said
    public ?Account $customer;
}

final class OrderItem extends Model
{
    public ?Product $product;                  // a property that is a model belongs to it, by product_id
}
```

`#[HasOne]` is for one related row; `#[BelongsToMany(Tag::class)]` is many to many, through `order_tag` unless the attribute names another pivot.

Relations are loaded when you ask, and reading a property never runs a query:

```php
// The receipt page: an order, its lines and their products, in three queries
$order = Order::query()->with('lines.product', 'customer')->find($id);

// Only some of them
Order::query()->with('lines', fn (ModelQuery $q) => $q->where('quantity', '>', 1))->get();

// For models already held
Order::load($orders, 'customer');

// One model's related rows as a query of their own, where a limit is allowed
$recent = $account->related('orders')->latest('id')->limit(5)->get();

// Rows that have related rows
Account::query()->whereHas('orders', fn (ModelQuery $q) => $q->where('status', OrderStatus::Shipped))->get();
```

Reading a relation that was not loaded is PHP's error for an uninitialised property, never a quiet query; `isset($order->lines)` says whether it is loaded. Each relation is one query, however many models there are.

## Writing

```php
// A new row: its times are kept, and a null is left to the database's default
$product = new Product();
$product->sku = 'TEA-001';
$product->name = 'Green tea';
$product->price = 450;
$product->save();                              // $product->id is set

// A change: only what changed is written, with updated_at
$product->stock = 35;
$product->save();

$product->refresh();                           // read again from the database
$product->delete();
```

Several at once:

| What you need | Write | What happens |
| --- | --- | --- |
| Their ids, or all of them or none | `Product::saveMany([$a, $b])` | One transaction, a statement for each; returns the models with their ids |
| Speed, and not the ids | `OrderItem::insertMany($items)` | One statement for all of them, 500 rows at a time; returns how many were inserted. Models the database numbers stay without their ids |
| A change to rows not loaded | `Product::query()->where('stock', '<', 10)->increment('stock', 60)` | One statement; `update()`, `increment()`, `decrement()` and `delete()`, with `updated_at` kept |

In the sample, the lines of a placed order are written with `insertMany()`, and `Restock` refills every product running low in one statement. `saveMany()` is for an import or an administrator's batch edit.

## Transactions

A model call runs in the transaction it is written in, with nothing to pass:

```php
$this->db->transaction(function (Connection $tx) use ($id): void {
    $order = Order::query()->lockForUpdate()->find($id) ?? throw new HttpException(404, 'No such order.');
    $order->status = OrderStatus::Shipped;
    ++$order->version;
    $order->save();                                        // in this transaction
    $this->queue->within($tx)->push(SignalOrderShipped::class, orderId: $id);
});
```

When the transaction is rolled back, a deadlock for one, the models saved or deleted in it are again what the database holds: a new one is new, without its key. The same callback can then be run again.

- The query builder does not follow the transaction by itself: in one, write `$tx->table(...)`, not `$db->table(...)`.
- Start a query where it runs: one started inside a transaction and run after it ends is refused.

## JSON for APIs

An action that returns a model, or a list of them, answers with JSON: the columns and the relations that were loaded, never the unloaded ones nor a `#[Hidden]` property. Dates are ISO 8601 unless the model says otherwise:

```php
protected function serializeDate(\DateTimeInterface $date): string
{
    return $date->format('Y-m-d H:i:s');      // as the sample's API gives a time
}
```

For another shape, give the model a method; the sample's `Product::summary()` is the six fields its product list gives.

## When not to use a model

| For | Use | In the sample |
| --- | --- | --- |
| Totals, counts, grouped reports | The query builder | `ListCategories`, `BuildDailyReport` |
| The typed shape of one query's result | An `into()` record | `ListMyOrders` |
| A counter changed by many at once | `increment()` or `decrement()` on a query | The stock taken in `PlaceOrder` |
| A table with no model, the queue's, the cache's | The query builder | |

## What is not there

- Loading a relation by reading it: `$order->lines` never runs a query.
- Events, observers and global scopes: what happens is written where it happens.
- Soft deletes: a column of your own, and a condition on it.
- A collection class: a list is a PHP array.
- Polymorphic relations and keys of several columns.

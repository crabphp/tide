# Changelog

## Unreleased

- `select()` and `except()` read some of a model's own columns. The others are left uninitialised, so reading one fails instead of giving a default or a null, JSON leaves them out and `save()` writes only what changed. The key, the keys relations are matched by and a cursor page's order columns are always read.
- **Tide does the ORM's job only (breaking).** It maps rows and relations and saves models, and does what the model's types can say; what they cannot is the query builder's. A model query returns models only, with no joins and no `into()` handoff: filter by related rows with `whereHas()`. Removed from model queries: `tap()`, `when()`, `whereRaw()` and `orWhereRaw()`, `orderByDesc()`, `latest()`, `oldest()`, `forPage()`, the aggregates `sum()`, `avg()`, `min()`, `max()`, `pluck()`, `value()` and `count($column)`, and the writes `update()`, `increment()`, `decrement()` and `delete()`. Removed from models: `saveMany()` and `insertMany()`. Each is the query builder's, started on the database: `$db->current()->table('books')->where(...)->pluck('title')`. Use `orderBy($column, 'desc')`, a PHP `if` for an optional condition, and `save()` for each model inside a transaction.
- **Behaviour change:** an insert writes null as NULL. A property never given a value, declared without a default, is what is left to the database's default; a `= null` default or an assigned null used to be left out too, so the row held the default while the model said null. Declare a column whose database default you want without a PHP default.
- A save that would have Tide fill in a hooked property, a database-numbered key or a kept time in an empty property, is refused before it writes: PHP cannot make such a property uninitialised again when the transaction is rolled back, and a retry reused the old key.
- A JSON-path condition, `where('meta->colour', 'blue')`, is written with the model's table.
- A failed `associate()` leaves the model as it was. A refused `MorphTo` target used to have its type column set already, so the next `save()` stored the wrong owner.
- A model query cannot run after the loan it was made in, even when the same connection is lent again (with the framework's loan guard).
- A model query's pages are `Page<Book>` and `CursorPage<Book>`, and `chunkById()` passes `list<Book>`, for static analysis.
- Polymorphic relations: `#[MorphTo]` belongs to one of the models its union type lists, by a type column holding the model's morph name, its table's name or `#[Table(morph: ...)]`, and an id column. `#[MorphMany]` and `#[MorphOne]` are its other side and keep every read to their own type. `associate()` points a `BelongsTo` or `MorphTo` at a model, and `Model::morphName()` gives the name stored for it.

## 0.1.0 — 2026-11-12

The first release, for `crabphp/framework` ^0.7.

- `Model::saveMany()` saves several models of one class, new or stored, in one transaction, and returns them with their keys and times.
- `Model::insertMany()` inserts new models of one class together and returns how many rows it inserted; models whose key was given are stored afterwards.
- An empty alternative, `orWhere([])` or a group that adds nothing, after a real filter on a `related()` query is left out instead of reaching every child.
- A model says how its dates are written in JSON by overriding `serializeDate()`; ISO 8601 with the offset unless it does.
- `whereRaw()` and `orWhereRaw()` on a model query, with bindings, for a condition it has no method for; `tap()` inside a condition closure is refused and points to them.
- On a many-to-many relation, the columns given to `pluck()`, `count()`, `chunkById()` and a two-argument `whereColumn()` are the related model's.
- An empty filter (an empty array, an empty group, a `when()` that adds nothing) before the first `orWhere()` of a `related()` query no longer lets that `orWhere()` reach every child.
- A rollback puts back the times Tide wrote, so a retry stamps its own; kept times are in PHP's default zone, as they read back.
- A model that only extends `Model` works: its table is named after the class (`OrderItem` reads `order_items`), a property that is another model belongs to it, and relation keys and the pivot are named after the models. `#[Table]` and the relation attributes' names are now optional and say the exceptions.
- `created_at` and `updated_at` are kept when a model declares them as dates, in saves and in bulk changes, from the application's clock. `#[Timestamps]` names other columns or turns one off. `Tide::use()` takes the clock as a second argument.
- A null property is left out of an insert, so the database's default applies.
- A model saved or deleted in a transaction that is rolled back is again what the database holds, so the callback can be run again.
- Persistence and relation matching use raw mapped values; JSON reads only visible mapped properties through getters. Asymmetric column setters are supported; hooked or asymmetric relations are refused.
- Queries retain their database and refuse a captured loan that is no longer current. Related model predicates keep their parent constraint across OR conditions.
- Bulk writes validate model columns and convert increment/decrement extras; self-table relation predicates on bulk writes are refused with a keys-first alternative.
- Grouping closures now receive `ModelQuery`; default cursors use the declared key, and chunks inherit the framework's ordered-query refusal.
- Mutable dates keep immutable snapshots. Single relations hydrate only the winning row per parent key; default related-key ordering applies to eager loads only.

- Typed whole-row models with explicit table and column mapping, casts, hook-safe hydration and JSON hiding.
- Query-builder conditions, aggregates, pagination, chunks and bulk changes through contract types.
- Explicit belongs-to, has-one, has-many and many-to-many relations, batched loading, nested constraints and relation existence queries.
- Snapshot-based inserts and changed-column updates, stored-key deletion and refresh, with current-transaction connections.
- Lazy plugin registration, SQLite and both MySQL driver tests, executable README examples and reading measurements.

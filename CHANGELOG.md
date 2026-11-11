# Changelog

## Unreleased

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

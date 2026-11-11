# Changelog

## Unreleased

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

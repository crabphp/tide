<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\CursorPage;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Field;
use Crab\Contracts\Database\Page;
use Crab\Contracts\Database\Query;

/**
 * A query of models whose connection is fixed when the query is made. It reads
 * every column the model declares, or those select() and except() choose, with
 * the key and the keys its relations need always among them.
 *
 * @template T of Model
 */
final class ModelQuery
{
    private Query $query;
    private ?Query $conditions = null;
    private array $relations = [];
    /**
     * @var array<string, true> Tables reached by relation predicates, including nested ones.
     */
    private array $relationTables = [];
    private ?\Closure $scope = null;
    public readonly Connection $connection;
    private bool $ordered = false;
    private bool $qualified = false;
    private bool $filtered = false;
    private bool $windowed = false;
    /**
     * @var array<string, true>|null The model columns chosen by select() or except(), or null for every one.
     */
    private ?array $selected = null;
    /**
     * @var array<string, true> The model columns the query is ordered by, which a cursor is made of.
     */
    private array $orderColumns = [];
    /**
     * @var array<string, true> Columns a row may hold that are no model column, as a pivot's key.
     */
    private array $allowed = [];
    public readonly string $qualifier;

    /**
     * @internal Made by Model and the relation loader.
     */
    public function __construct(
        public readonly ModelMap $map,
        public readonly Database $database,
        ?Connection $connection = null,
        ?string $alias = null,
    ) {
        $this->connection = $connection ?? $database->current();
        $this->qualifier = $alias ?? $map->table;
        $this->query = $this->connection->table($map->table, $alias);
        $this->query->select(...array_map(
            fn(string $column): string => $this->qualifier . '.' . $column,
            array_keys($map->columns),
        ));
    }

    public function __clone(): void
    {
        $this->assertOutsideGroup('cloned');
        $this->query = clone $this->query;
    }

    public function when(mixed $condition, \Closure $then, ?\Closure $else = null): static
    {
        if ($condition) {
            $then($this);
        } elseif ($else !== null) {
            $else($this);
        }
        return $this;
    }

    public function tap(\Closure $callback): static
    {
        if ($this->conditions !== null) {
            throw new \LogicException(
                "{$this->map->class} query cannot be tapped inside the closure of where(), with(), whereHas() or whereDoesntHave(); write a condition the model query has no method for with whereRaw().",
            );
        }
        $this->assertCurrent();
        $sql = $this->query->toSql();
        $callback($this->query);
        $after = $this->query->toSql();
        if (substr_count(strtolower($after), ' join ') > substr_count(strtolower($sql), ' join ')) {
            // One row per related row: a post joined to its comments comes back once for each, and every copy saves.
            throw new \LogicException(
                "{$this->map->class} query was given a join in tap(); a model query reads its own rows only. Filter by related rows with whereHas(), load them with with(), or join on the query builder: \$db->table(...)->join(...)->into(YourRecord::class).",
            );
        }
        if (self::projection($after) !== self::projection($sql)) {
            // A joined column of the same name would replace the model's own, its key included, before any
            // check of the row could see it, and save() would then write another row.
            throw new \LogicException(
                "{$this->map->class} query had the columns it reads changed in tap(); a model reads only its own columns. tap() is for conditions and locks: read joined or computed columns with the query builder, \$db->table(...)->into(YourRecord::class).",
            );
        }
        return $this;
    }

    /**
     * What a statement reads: its SQL up to the table it reads from.
     */
    private static function projection(string $sql): string
    {
        $from = stripos($sql, ' from ');
        return $from === false ? $sql : substr($sql, 0, $from);
    }

    /**
     * Reads only these columns. The others are left uninitialised on each model:
     * reading one fails, JSON leaves it out and save() never writes it. The key, a
     * column with a hook and a default, and the keys the query's relations and
     * cursor need are read as well.
     */
    public function select(string ...$columns): static
    {
        if ($columns === []) {
            throw new \LogicException(
                "{$this->map->class} select() needs at least one column; leave select() out to read every column.",
            );
        }
        $chosen = [];
        foreach ($columns as $column) {
            $chosen[$this->chosen($column, 'select')] = true;
        }
        $this->selected = $chosen;
        $this->applySelection();
        return $this;
    }

    /**
     * Reads every column the model declares except these, as select() with the rest.
     */
    public function except(string ...$columns): static
    {
        if ($columns === []) {
            throw new \LogicException(
                "{$this->map->class} except() needs at least one column; leave except() out to read every column.",
            );
        }
        $chosen = array_fill_keys(array_keys($this->map->columns), true);
        foreach ($columns as $column) {
            unset($chosen[$this->chosen($column, 'except')]);
        }
        $this->selected = $chosen;
        $this->applySelection();
        return $this;
    }

    /**
     * @internal Lets rows hold a column that is no model column, as the relation loader's pivot key.
     */
    public function allow(string $column): void
    {
        $this->allowed[$column] = true;
    }

    /**
     * @internal Refuses a row with columns the model does not read, as a join in tap() adds, instead of
     * dropping them unseen. The first row says it for all: every row of a statement has the same columns.
     *
     * @param array<string, mixed> $row
     */
    public function assertShape(array $row): void
    {
        $extra = array_diff_key($row, $this->selected ?? $this->map->columns, $this->allowed);
        if ($extra !== []) {
            throw new \LogicException(
                "{$this->map->class} query read "
                . implode(', ', array_keys($extra))
                . ', which '
                . (count($extra) === 1 ? 'is' : 'are')
                . ' no column of the model; a model holds only its own columns, so read the others with the query builder: $db->table(...)->into(YourRecord::class).',
            );
        }
    }

    /**
     * @internal Makes a relation loader's or a cursor's column part of a partial selection.
     */
    public function need(string $column): void
    {
        if ($this->selected === null || isset($this->selected[$column]) || !isset($this->map->columns[$column])) {
            return;
        }
        $this->selected[$column] = true;
        $this->query->addSelect($this->qualifier . '.' . $column);
    }

    /**
     * @internal Adds what reading needs to a partial selection: the local keys of the relations to load.
     */
    public function prepareRead(): void
    {
        if ($this->selected === null) {
            return;
        }
        foreach (array_keys($this->relations) as $name) {
            foreach ($this->map->relation($name)->localColumns($this->map) as $column) {
                $this->need($column);
            }
        }
    }

    /**
     * @internal One row as a model, with only the selected columns when the query chose some.
     *
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): Model
    {
        if ($this->selected === null) {
            return $this->map->hydrate($row);
        }
        $only = [];
        foreach ($this->selected as $column => $_) {
            $only[$this->map->columns[$column]->property] = true;
        }
        return $this->map->hydrate($row, $only);
    }

    public function with(string|\Closure ...$relations): static
    {
        $this->relations = Relations::tree($this->map, $relations, $this->relations);
        return $this;
    }

    public function whereHas(string $relation, ?\Closure $constraint = null): static
    {
        $this->condition('whereExists', [Relations::exists($this, $relation, $constraint)]);
        return $this;
    }

    public function whereDoesntHave(string $relation, ?\Closure $constraint = null): static
    {
        $this->condition('whereNotExists', [Relations::exists($this, $relation, $constraint)]);
        return $this;
    }

    /**
     * @internal Allows relations to compose the builder without naming its implementation.
     */
    public function builder(): Query
    {
        $this->assertCurrent();
        return $this->query;
    }

    /**
     * @internal Eager constraints cannot cut the combined list.
     */
    public function prepareRelation(Relation $relation): void
    {
        if ($this->windowed) {
            throw new \LogicException(
                "{$relation->where} has an eager limit or offset, which cuts the whole list; use related('{$relation->property}') for a single model's limited query.",
            );
        }
        if (!$this->ordered && $this->map->key !== null) {
            $this->orderBy($this->qualifier . '.' . $this->map->key->column);
        }
    }

    /**
     * @internal Group a constraint's conditions so OR cannot escape its relation.
     * Ordering, windows and eager paths still belong to this model query.
     */
    public function constrain(\Closure $constraint): void
    {
        $this->where($constraint);
    }

    /**
     * @internal Load the constraints' own with() paths once for a merged batch.
     * @param list<Model> $models
     */
    public function loadRelations(array $models): void
    {
        $this->assertCurrent();
        Relations::load($this, $models, $this->relations);
    }

    /**
     * @return list<T>
     */
    public function get(): array
    {
        $this->assertCurrent();
        $this->prepareRead();
        return $this->models($this->query->get());
    }

    /**
     * @return T|null
     */
    public function first(): ?Model
    {
        $this->assertCurrent();
        $this->prepareRead();
        $row = $this->query->first();
        return $row === null ? null : $this->models([$row])[0];
    }

    /**
     * @return T|null
     */
    public function find(int|string $id): ?Model
    {
        $this->assertCurrent();
        $this->prepareRead();
        $key = $this->map->requireKey();
        $row = $this->query->find($id, $this->qualifier . '.' . $key->column);
        return $row === null ? null : $this->models([$row])[0];
    }

    /**
     * @return Page<T>
     */
    public function paginate(int $perPage = 15, int $page = 1): Page
    {
        $this->assertCurrent();
        $this->prepareRead();
        $result = $this->query->paginate($perPage, $page);
        return new Page(
            $this->models($result->items),
            $result->perPage,
            $result->currentPage,
            $result->hasMore,
            $result->total,
        );
    }

    /**
     * @return Page<T>
     */
    public function simplePaginate(int $perPage = 15, int $page = 1): Page
    {
        $this->assertCurrent();
        $this->prepareRead();
        $result = $this->query->simplePaginate($perPage, $page);
        return new Page(
            $this->models($result->items),
            $result->perPage,
            $result->currentPage,
            $result->hasMore,
            $result->total,
        );
    }

    /**
     * @return CursorPage<T>
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, ?string $unique = null): CursorPage
    {
        $this->assertCurrent();
        $this->prepareRead();
        $unique ??= $this->map->requireKey()->column;
        // A cursor is made of the columns the page is ordered by, so a partial selection reads them.
        foreach ([...array_keys($this->orderColumns), $unique] as $column) {
            $this->need($column);
        }
        $result = $this->query->cursorPaginate($perPage, $cursor, $unique);
        return new CursorPage($this->models($result->items), $result->perPage, $result->nextCursor);
    }

    /**
     * @param \Closure(list<T>): mixed $callback
     */
    public function chunkById(int $size, \Closure $callback, string $column = 'id'): void
    {
        $this->assertCurrent();
        if (func_num_args() < 3) {
            $column = $this->map->requireKey()->column;
        }
        $this->prepareRead();
        $this->need($column);
        $this->query->chunkById(
            $size,
            fn(array $rows): mixed => $callback($this->models($rows)),
            $this->named($column),
        );
    }

    public function update(array $values): int
    {
        $this->assertWritable();
        return $this->query->update($this->written($values));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<T>
     */
    private function models(array $rows): array
    {
        if ($rows !== []) {
            $this->assertShape($rows[0]);
        }
        $models = array_map($this->hydrate(...), $rows);
        $this->loadRelations($models);
        return $models;
    }

    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        $arguments = $column instanceof \Closure ? [$this->group($column)] : func_get_args();
        $this->condition('where', $arguments);
        return $this;
    }

    public function orWhere(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        $arguments = $column instanceof \Closure ? [$this->group($column)] : func_get_args();
        $this->condition('orWhere', $arguments);
        return $this;
    }

    public function whereIn(string $column, array|Query $values): static
    {
        $this->condition('whereIn', [$column, $values]);
        return $this;
    }

    public function orWhereIn(string $column, array|Query $values): static
    {
        $this->condition('orWhereIn', [$column, $values]);
        return $this;
    }

    public function whereNotIn(string $column, array|Query $values): static
    {
        $this->condition('whereNotIn', [$column, $values]);
        return $this;
    }

    public function orWhereNotIn(string $column, array|Query $values): static
    {
        $this->condition('orWhereNotIn', [$column, $values]);
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->condition('whereNull', [$column]);
        return $this;
    }

    public function orWhereNull(string $column): static
    {
        $this->condition('orWhereNull', [$column]);
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->condition('whereNotNull', [$column]);
        return $this;
    }

    public function orWhereNotNull(string $column): static
    {
        $this->condition('orWhereNotNull', [$column]);
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->condition('whereBetween', [$column, $from, $to]);
        return $this;
    }

    public function orWhereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->condition('orWhereBetween', [$column, $from, $to]);
        return $this;
    }

    public function whereNotBetween(string $column, mixed $from, mixed $to): static
    {
        $this->condition('whereNotBetween', [$column, $from, $to]);
        return $this;
    }

    public function orWhereNotBetween(string $column, mixed $from, mixed $to): static
    {
        $this->condition('orWhereNotBetween', [$column, $from, $to]);
        return $this;
    }

    /**
     * A condition written in SQL, for what the model query has no method for, such as
     * whereRaw('quantity * price >= ?', [100]). Every value is a binding.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->condition('whereRaw', [$sql, $bindings]);
        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->condition('orWhereRaw', [$sql, $bindings]);
        return $this;
    }

    public function whereColumn(string $first, string $operator, ?string $second = null): static
    {
        $this->condition('whereColumn', [$first, $operator, $second]);
        return $this;
    }

    public function orWhereColumn(string $first, string $operator, ?string $second = null): static
    {
        $this->condition('orWhereColumn', [$first, $operator, $second]);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->ordered = true;
        $this->ordering($column);
        $this->query->orderBy($this->named($column), $direction);
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        $this->ordered = true;
        $this->ordering($column);
        $this->query->orderByDesc($this->named($column));
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        $this->ordered = true;
        $this->ordering($column);
        $this->query->latest($this->named($column));
        return $this;
    }

    public function oldest(string $column = 'created_at'): static
    {
        $this->ordered = true;
        $this->ordering($column);
        $this->query->oldest($this->named($column));
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->windowed = true;
        $this->query->limit($limit);
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->windowed = true;
        $this->query->offset($offset);
        return $this;
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        $this->windowed = true;
        $this->query->forPage($page, $perPage);
        return $this;
    }

    public function lockForUpdate(): static
    {
        $this->query->lockForUpdate();
        return $this;
    }

    public function toSql(): string
    {
        return $this->query->toSql();
    }

    public function bindings(): array
    {
        return $this->query->bindings();
    }

    public function exists(): bool
    {
        $this->assertCurrent();
        return $this->query->exists();
    }

    public function count(string $column = '*'): int
    {
        $this->assertCurrent();
        return $this->query->count($column === '*' ? $column : $this->named($column));
    }

    public function sum(string $column): int|float
    {
        $this->assertCurrent();
        return $this->query->sum($this->named($column));
    }

    public function avg(string $column): ?float
    {
        $this->assertCurrent();
        return $this->query->avg($this->named($column));
    }

    public function min(string $column): mixed
    {
        $this->assertCurrent();
        return $this->query->min($this->named($column));
    }

    public function max(string $column): mixed
    {
        $this->assertCurrent();
        return $this->query->max($this->named($column));
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $this->assertCurrent();
        return $this->query->pluck($this->named($column), $key === null ? null : $this->named($key));
    }

    public function value(string $column): mixed
    {
        $this->assertCurrent();
        return $this->query->value($this->named($column));
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->assertWritable();
        $this->column($column);
        return $this->query->increment($column, $amount, $this->written($extra));
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->assertWritable();
        $this->column($column);
        return $this->query->decrement($column, $amount, $this->written($extra));
    }

    public function delete(): int
    {
        $this->assertWritable();
        return $this->query->delete();
    }

    /**
     * @internal A captured loan is usable only while it is current for its original database.
     */
    public function assertCurrent(): void
    {
        $this->assertOutsideGroup('run');
        if ($this->connection !== $this->database && $this->connection !== $this->database->current()) {
            throw new \LogicException(
                "{$this->map->class} query was started inside a transaction or run() that has ended or is not the innermost one now; start the query where it runs.",
            );
        }
    }

    /**
     * @internal Keep the parent predicate in every top-level OR branch.
     */
    /**
     * @param array<string, string> $also Further columns the parent condition fixes, as a morph's type column.
     */
    public function scope(string $column, int|string|null $key, array $also = []): void
    {
        $this->scope = static function (Query $query) use ($column, $key, $also): Query {
            $query = $key === null ? $query->whereIn($column, []) : $query->where($column, $key);
            foreach ($also as $fixed => $value) {
                $query = $query->where($fixed, $value);
            }
            return $query;
        };
        ($this->scope)($this->query);
    }

    /**
     * @internal Propagate tables reached through a relation's path and its constraints.
     */
    public function includeRelation(self $related, ?string $pivot = null): void
    {
        $this->relationTables[$related->map->table] = true;
        $this->relationTables += $related->relationTables;
        if ($pivot !== null) {
            $this->relationTables[$pivot] = true;
        }
    }

    private function group(\Closure $constraint): \Closure
    {
        return function (Query $conditions) use ($constraint): void {
            $previous = $this->conditions;
            $this->conditions = $conditions;
            try {
                $constraint($this);
            } finally {
                $this->conditions = $previous;
            }
        };
    }

    /**
     * Inside the closure of where() the conditions being written are not yet part of the query,
     * so running or copying it there would leave them out.
     */
    private function assertOutsideGroup(string $what): void
    {
        if ($this->conditions !== null) {
            throw new \LogicException(
                "{$this->map->class} query cannot be {$what} inside the closure of where(), with(), whereHas() or whereDoesntHave(); add conditions there, and run the query after the closure.",
            );
        }
    }

    /**
     * A model column named plainly, written with its table where the query joins another, a pivot for one,
     * so that the pivot's own column of that name is not the one meant.
     */
    private function named(string $column): string
    {
        // Always with its table, which a relation's own query needs once it joins a pivot; a JSON path,
        // meta->colour, by its column.
        $base = str_contains($column, '->') ? strstr($column, '->', true) : $column;
        return isset($this->map->columns[$base]) ? $this->qualifier . '.' . $column : $column;
    }

    /**
     * @internal Says that the query joins another table, as a many-to-many relation's does.
     */
    public function joined(): void
    {
        $this->qualified = true;
    }

    private function condition(string $method, array $arguments): void
    {
        if ($arguments !== []) {
            if (is_string($arguments[0])) {
                $arguments[0] = $this->named($arguments[0]);
            } elseif (is_array($arguments[0]) && !array_is_list($arguments[0])) {
                $named = [];
                foreach ($arguments[0] as $column => $value) {
                    $named[is_string($column) ? $this->named($column) : $column] = $value;
                }
                $arguments[0] = $named;
            }
            if ($method === 'whereColumn' || $method === 'orWhereColumn') {
                // Without an operator, the second is the other column.
                $other = isset($arguments[2]) ? 2 : 1;
                $arguments[$other] = $this->named((string) $arguments[$other]);
            }
        }
        if ($this->scope !== null && $this->conditions === null && !$this->filtered) {
            // The first condition after the parent's has nothing to be an alternative to. One that
            // adds nothing, an empty array or group, does not count as the first.
            if (str_starts_with($method, 'orWhere')) {
                $method = lcfirst(substr($method, 2));
            }
            $before = $this->query->toSql();
            $this->query->{$method}(...$arguments);
            $this->filtered = $this->query->toSql() !== $before;
            return;
        }
        if ($this->scope !== null && $this->conditions === null && str_starts_with($method, 'orWhere')) {
            $scope = $this->scope;
            // (parent AND a) OR (parent AND b) keeps the parent outside the user's OR.
            $this->query->orWhere(static function (Query $branch) use ($scope, $method, $arguments): void {
                $empty = $branch->toSql();
                $branch->where(static fn(Query $group): Query => $group->{$method}(...$arguments));
                // An alternative that adds nothing is none: without the parent's condition either,
                // the branch is empty and the builder leaves it out.
                if ($branch->toSql() !== $empty) {
                    $scope($branch);
                }
            });
            return;
        }
        ($this->conditions ?? $this->query)->{$method}(...$arguments);
    }

    private function assertWritable(): void
    {
        $this->assertCurrent();
        if (isset($this->relationTables[$this->map->table])) {
            throw new \LogicException(
                "{$this->map->class} has a relation condition reaching its own table; read the keys first and write with whereIn() instead of a bulk write with whereHas() or whereDoesntHave().",
            );
        }
    }

    /**
     * A column named to select() or except(), plainly or with the model's table.
     */
    private function chosen(string $column, string $method): string
    {
        $plain = str_starts_with($column, $this->qualifier . '.')
            ? substr($column, strlen($this->qualifier) + 1)
            : $column;
        if (!isset($this->map->columns[$plain])) {
            throw new \LogicException(
                "{$this->map->class} {$method}() names \"{$column}\", which is not one of its columns; name "
                . implode(', ', array_keys($this->map->columns))
                . ', or use a record for a column the model does not declare.',
            );
        }
        return $plain;
    }

    /**
     * The builder reads the chosen columns, in the model's order, with those every query must read.
     */
    private function applySelection(): void
    {
        foreach ($this->map->fields as $name => $field) {
            if (!$this->map->mustRead($name)) {
                continue;
            }
            // A hooked property keeps its default when left out, which would look like the row's value.
            $this->selected[$field->column] = true;
        }
        if ($this->map->key !== null) {
            $this->selected[$this->map->key->column] = true;
        }
        $columns = array_values(array_filter(
            array_keys($this->map->columns),
            fn(string $column): bool => isset($this->selected[$column]),
        ));
        $this->query->select(...array_map(fn(string $column): string => $this->qualifier . '.' . $column, $columns));
    }

    /**
     * Remembers a model column the query is ordered by, so a partial selection still reads it for a cursor.
     */
    private function ordering(string $column): void
    {
        $plain = str_starts_with($column, $this->qualifier . '.')
            ? substr($column, strlen($this->qualifier) + 1)
            : $column;
        if (isset($this->map->columns[$plain])) {
            $this->orderColumns[$plain] = true;
        }
    }

    private function column(string $column): Field
    {
        return (
            $this->map->columns[$column] ?? throw new \LogicException(
                "{$this->map->class} does not declare the column \"{$column}\"; use the query builder to write a column the model does not declare.",
            )
        );
    }

    /**
     * @param bool $stamped Whether a model with #[Timestamps] has its updated time written with them.
     */
    private function written(array $values, bool $stamped = true): array
    {
        foreach ($values as $column => $value) {
            $values[$column] = $this->column($column)->toColumn($value);
        }
        $stamp = $this->map->updatedAt;
        if ($stamped && $stamp !== null && !array_key_exists($stamp->column, $values)) {
            $values[$stamp->column] = $stamp->toColumn(Tide::now());
        }
        return $values;
    }
}

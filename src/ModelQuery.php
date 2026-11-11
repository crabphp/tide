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
 * A whole-row query whose connection is fixed when the query is made.
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
        $callback($this->query);
        return $this;
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
        return $this->models($this->query->get());
    }

    /**
     * @return T|null
     */
    public function first(): ?Model
    {
        $this->assertCurrent();
        $row = $this->query->first();
        return $row === null ? null : $this->models([$row])[0];
    }

    /**
     * @return T|null
     */
    public function find(int|string $id): ?Model
    {
        $this->assertCurrent();
        $key = $this->map->requireKey();
        $row = $this->query->find($id, $this->qualifier . '.' . $key->column);
        return $row === null ? null : $this->models([$row])[0];
    }

    public function paginate(int $perPage = 15, int $page = 1): Page
    {
        $this->assertCurrent();
        $result = $this->query->paginate($perPage, $page);
        return new Page(
            $this->models($result->items),
            $result->perPage,
            $result->currentPage,
            $result->hasMore,
            $result->total,
        );
    }

    public function simplePaginate(int $perPage = 15, int $page = 1): Page
    {
        $this->assertCurrent();
        $result = $this->query->simplePaginate($perPage, $page);
        return new Page(
            $this->models($result->items),
            $result->perPage,
            $result->currentPage,
            $result->hasMore,
            $result->total,
        );
    }

    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, ?string $unique = null): CursorPage
    {
        $this->assertCurrent();
        $result = $this->query->cursorPaginate($perPage, $cursor, $unique ?? $this->map->requireKey()->column);
        return new CursorPage($this->models($result->items), $result->perPage, $result->nextCursor);
    }

    public function chunkById(int $size, \Closure $callback, string $column = 'id'): void
    {
        $this->assertCurrent();
        if (func_num_args() < 3) {
            $column = $this->map->requireKey()->column;
        }
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
        $models = array_map($this->map->hydrate(...), $rows);
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
        $this->query->orderBy($this->named($column), $direction);
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        $this->ordered = true;
        $this->query->orderByDesc($this->named($column));
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        $this->ordered = true;
        $this->query->latest($this->named($column));
        return $this;
    }

    public function oldest(string $column = 'created_at'): static
    {
        $this->ordered = true;
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
    public function scope(string $column, int|string|null $key): void
    {
        $this->scope = static fn(Query $query): Query => (
            $key === null ? $query->whereIn($column, []) : $query->where($column, $key)
        );
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
        return $this->qualified && isset($this->map->columns[$column]) ? $this->qualifier . '.' . $column : $column;
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
        if ($this->qualified && $arguments !== []) {
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

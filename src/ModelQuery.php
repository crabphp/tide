<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\CursorPage;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Page;
use Crab\Contracts\Database\Query;

/**
 * A query of models: conditions, order and a window on the model's own rows,
 * its relations loaded with with(), and models back. Its connection is fixed
 * when the query is made. Totals, joins, projections and writes to many rows
 * are the query builder's.
 *
 * @template T of Model
 */
final class ModelQuery
{
    private Query $query;
    private ?Query $conditions = null;
    private array $relations = [];
    private ?\Closure $scope = null;
    public readonly Connection $connection;
    private bool $ordered = false;
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

    /**
     * @internal One row as a model.
     *
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): Model
    {
        return $this->map->hydrate($row);
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

    /**
     * @return Page<T>
     */
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

    /**
     * @return Page<T>
     */
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

    /**
     * @return CursorPage<T>
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, ?string $unique = null): CursorPage
    {
        $this->assertCurrent();
        $result = $this->query->cursorPaginate($perPage, $cursor, $unique ?? $this->map->requireKey()->column);
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
        $this->query->chunkById(
            $size,
            fn(array $rows): mixed => $callback($this->models($rows)),
            $this->named($column),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<T>
     */
    private function models(array $rows): array
    {
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

    /**
     * How many models the query matches, without reading them.
     */
    public function count(): int
    {
        $this->assertCurrent();
        return $this->query->count();
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
}

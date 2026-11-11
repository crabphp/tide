<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\CursorPage;
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
    private bool $ordered = false;
    private bool $windowed = false;
    public readonly string $qualifier;

    /**
     * @internal Made by Model and the relation loader.
     */
    public function __construct(
        public readonly ModelMap $map,
        public readonly Connection $connection,
        ?string $alias = null,
    ) {
        $this->qualifier = $alias ?? $map->table;
        $this->query = $connection->table($map->table, $alias);
        $this->query->select(...array_map(
            fn(string $column): string => $this->qualifier . '.' . $column,
            array_keys($map->columns),
        ));
    }

    public function __clone(): void
    {
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
        ($this->conditions ?? $this->query)->whereExists(Relations::exists($this, $relation, $constraint));
        return $this;
    }

    public function whereDoesntHave(string $relation, ?\Closure $constraint = null): static
    {
        ($this->conditions ?? $this->query)->whereNotExists(Relations::exists($this, $relation, $constraint));
        return $this;
    }

    /**
     * @internal Allows relations to compose the builder without naming its implementation.
     */
    public function builder(): Query
    {
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
        $this->query->where(function (Query $conditions) use ($constraint): void {
            $previous = $this->conditions;
            $this->conditions = $conditions;
            try {
                $constraint($this);
            } finally {
                $this->conditions = $previous;
            }
        });
    }

    /**
     * @internal Load the constraints' own with() paths once for a merged batch.
     * @param list<Model> $models
     */
    public function loadRelations(array $models): void
    {
        Relations::load($this->map, $this->connection, $models, $this->relations);
    }

    /**
     * @return list<T>
     */
    public function get(): array
    {
        return $this->models($this->query->get());
    }

    /**
     * @return T|null
     */
    public function first(): ?Model
    {
        $row = $this->query->first();
        return $row === null ? null : $this->models([$row])[0];
    }

    /**
     * @return T|null
     */
    public function find(int|string $id): ?Model
    {
        $key = $this->map->requireKey();
        $row = $this->query->find($id, $this->qualifier . '.' . $key->column);
        return $row === null ? null : $this->models([$row])[0];
    }

    public function paginate(int $perPage = 15, int $page = 1): Page
    {
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
        $result = $this->query->simplePaginate($perPage, $page);
        return new Page(
            $this->models($result->items),
            $result->perPage,
            $result->currentPage,
            $result->hasMore,
            $result->total,
        );
    }

    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, string $unique = 'id'): CursorPage
    {
        $result = $this->query->cursorPaginate($perPage, $cursor, $unique);
        return new CursorPage($this->models($result->items), $result->perPage, $result->nextCursor);
    }

    public function chunkById(int $size, \Closure $callback, string $column = 'id'): void
    {
        if (func_num_args() < 3) {
            $column = $this->map->requireKey()->column;
        }
        $this->query->chunkById($size, fn(array $rows): mixed => $callback($this->models($rows)), $column);
    }

    public function update(array $values): int
    {
        foreach ($values as $column => $value) {
            if (!isset($this->map->columns[$column])) {
                continue;
            }
            $values[$column] = $this->map->columns[$column]->toColumn($value);
        }
        return $this->query->update($values);
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
        ($this->conditions ?? $this->query)->where(...func_get_args());
        return $this;
    }

    public function orWhere(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        ($this->conditions ?? $this->query)->orWhere(...func_get_args());
        return $this;
    }

    public function whereIn(string $column, array|Query $values): static
    {
        ($this->conditions ?? $this->query)->whereIn($column, $values);
        return $this;
    }

    public function orWhereIn(string $column, array|Query $values): static
    {
        ($this->conditions ?? $this->query)->orWhereIn($column, $values);
        return $this;
    }

    public function whereNotIn(string $column, array|Query $values): static
    {
        ($this->conditions ?? $this->query)->whereNotIn($column, $values);
        return $this;
    }

    public function orWhereNotIn(string $column, array|Query $values): static
    {
        ($this->conditions ?? $this->query)->orWhereNotIn($column, $values);
        return $this;
    }

    public function whereNull(string $column): static
    {
        ($this->conditions ?? $this->query)->whereNull($column);
        return $this;
    }

    public function orWhereNull(string $column): static
    {
        ($this->conditions ?? $this->query)->orWhereNull($column);
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        ($this->conditions ?? $this->query)->whereNotNull($column);
        return $this;
    }

    public function orWhereNotNull(string $column): static
    {
        ($this->conditions ?? $this->query)->orWhereNotNull($column);
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        ($this->conditions ?? $this->query)->whereBetween($column, $from, $to);
        return $this;
    }

    public function orWhereBetween(string $column, mixed $from, mixed $to): static
    {
        ($this->conditions ?? $this->query)->orWhereBetween($column, $from, $to);
        return $this;
    }

    public function whereNotBetween(string $column, mixed $from, mixed $to): static
    {
        ($this->conditions ?? $this->query)->whereNotBetween($column, $from, $to);
        return $this;
    }

    public function orWhereNotBetween(string $column, mixed $from, mixed $to): static
    {
        ($this->conditions ?? $this->query)->orWhereNotBetween($column, $from, $to);
        return $this;
    }

    public function whereColumn(string $first, string $operator, ?string $second = null): static
    {
        ($this->conditions ?? $this->query)->whereColumn($first, $operator, $second);
        return $this;
    }

    public function orWhereColumn(string $first, string $operator, ?string $second = null): static
    {
        ($this->conditions ?? $this->query)->orWhereColumn($first, $operator, $second);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->ordered = true;
        $this->query->orderBy($column, $direction);
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        $this->ordered = true;
        $this->query->orderByDesc($column);
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        $this->ordered = true;
        $this->query->latest($column);
        return $this;
    }

    public function oldest(string $column = 'created_at'): static
    {
        $this->ordered = true;
        $this->query->oldest($column);
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
        return $this->query->exists();
    }

    public function count(string $column = '*'): int
    {
        return $this->query->count($column);
    }

    public function sum(string $column): int|float
    {
        return $this->query->sum($column);
    }

    public function avg(string $column): ?float
    {
        return $this->query->avg($column);
    }

    public function min(string $column): mixed
    {
        return $this->query->min($column);
    }

    public function max(string $column): mixed
    {
        return $this->query->max($column);
    }

    public function pluck(string $column, ?string $key = null): array
    {
        return $this->query->pluck($column, $key);
    }

    public function value(string $column): mixed
    {
        return $this->query->value($column);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->query->increment($column, $amount, $extra);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->query->decrement($column, $amount, $extra);
    }

    public function delete(): int
    {
        return $this->query->delete();
    }
}

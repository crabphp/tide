<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Connection;

abstract class Model implements \JsonSerializable
{
    /**
     * @var array<string, mixed>|null Null until read or saved; cast objects use their written value.
     */
    private ?array $storedValues = null;

    /**
     * @return ModelQuery<static>
     */
    public static function query(): ModelQuery
    {
        $map = ModelMap::of(static::class);
        return new ModelQuery($map, Tide::database($map->database));
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    /**
     * @param list<static> $models
     */
    public static function load(array $models, string|\Closure ...$relations): void
    {
        if ($models === []) {
            return;
        }
        $map = ModelMap::of(static::class);
        foreach ($models as $model) {
            if (!$model instanceof static || $model::class !== static::class) {
                throw new \LogicException(static::class
                . '::load() needs models of that class; pass one class at a time.');
            }
            if (!$model->stored()) {
                throw new \LogicException(static::class
                . '::load() received a new model; save it before loading relations.');
            }
        }
        $query = new ModelQuery($map, Tide::database($map->database));
        Relations::load($query, $models, Relations::tree($map, $relations));
    }

    public function save(): static
    {
        $map = ModelMap::of(static::class);
        $key = $map->requireKey();
        $values = $map->values($this);
        if ($this->storedValues === null) {
            // Zero is what an int property holds before the database numbers the row; MySQL takes it as a request for a number too.
            if (($values[$key->property] ?? null) === 0) {
                unset($values[$key->property]);
            }
            $givenKey = array_key_exists($key->property, $values);
            if (!$givenKey && $key->type === 'string') {
                throw new \LogicException(
                    static::class . "::\${$key->property} is a string key; give it a value before save().",
                );
            }
            // The times #[Timestamps] keeps, where the application gave none.
            $stamps = $before = [];
            foreach (array_filter([$map->createdAt, $map->updatedAt]) as $stamp) {
                if (($values[$stamp->property] ?? null) !== null) {
                    continue;
                }
                $before[$stamp->property] = array_key_exists($stamp->property, $values);
                $stamps[$stamp->property] = $values[$stamp->property] = Tide::now();
            }
            $written = [];
            foreach ($values as $name => $value) {
                // Null is left to the database: its default for the column, or null.
                if ($value === null) {
                    continue;
                }
                $field = $map->fields[$name];
                $written[$field->column] = $field->toColumn($value);
            }
            if ($written === []) {
                throw new \LogicException(static::class
                . '::save() has no value to write; give at least one column a value before saving.');
            }
            $connection = Tide::database($map->database)->current();
            $id = $connection->table($map->table)->insert($written);
            // Undone, the key the database gave and the times Tide wrote are forgotten too.
            $forget = $givenKey ? [] : [$key->property => false];
            $this->undoneBy($map, $connection, null, [...$forget, ...$before]);
            foreach ($stamps as $name => $value) {
                $map->assign($this, $name, $value);
            }
            if (!$givenKey) {
                $id = $key->fromColumn($id);
                $map->assign($this, $key->property, $id);
            }
            $stored = [];
            foreach ($map->values($this) as $name => $value) {
                $stored[$name] = $map->snapshot($map->fields[$name], $value);
            }
            $this->storedValues = $stored;
            return $this;
        }
        $written = $changed = [];
        foreach ($values as $name => $value) {
            $field = $map->fields[$name];
            $snapshot = $map->snapshot($field, $value);
            $present = array_key_exists($name, $this->storedValues);
            $same =
                $present
                && (
                    $value instanceof \DateTimeInterface && $field->cast === null
                        ? ($snapshot <=> $this->storedValues[$name]) === 0
                        : $snapshot === $this->storedValues[$name]
                );
            if ($same) {
                continue;
            }
            $written[$field->column] = $field->toColumn($value);
            $changed[$name] = $snapshot;
        }
        if ($changed === []) {
            return $this;
        }
        $stamp = $map->updatedAt;
        if ($stamp !== null && !array_key_exists($stamp->property, $changed)) {
            // A change is stamped, unless the application set the time itself.
            $now = Tide::now();
            $written[$stamp->column] = $stamp->toColumn($now);
            $changed[$stamp->property] = $now;
        } else {
            $stamp = null;
        }
        $connection = Tide::database($map->database)->current();
        $connection->table($map->table)->where($key->column, $this->storedValues[$key->property])->update($written);
        $restore = [];
        if ($stamp !== null) {
            $restore[$stamp->property] = $map->initialized($this, $stamp->property)
                ? [$map->value($this, $stamp->property)]
                : false;
        }
        $this->undoneBy($map, $connection, $this->storedValues, $restore);
        if ($stamp !== null) {
            $map->assign($this, $stamp->property, $changed[$stamp->property]);
        }
        $this->storedValues = array_replace($this->storedValues, $changed);
        foreach ($map->relations as $name => $relation) {
            if (
                !$relation->attribute instanceof BelongsTo
                || !array_key_exists($relation->attribute->foreignKey, $written)
            ) {
                continue;
            }
            unset($this->{$name});
        }
        return $this;
    }

    public function delete(): void
    {
        $map = ModelMap::of(static::class);
        $key = $map->requireKey();
        if ($this->storedValues === null) {
            throw new \LogicException(static::class . '::delete() needs a stored model; save it before deleting it.');
        }
        $connection = Tide::database($map->database)->current();
        $connection->table($map->table)->where($key->column, $this->storedValues[$key->property])->delete();
        $this->undoneBy($map, $connection, $this->storedValues);
        $this->storedValues = null;
    }

    /**
     * If the transaction a write ran in is rolled back, the model is again
     * what the database holds: new, or stored with the values it had, so
     * that running the transaction again writes it again. What Tide itself
     * put in a property, a generated key or a kept time, goes back to what
     * it was; what the application assigned stays.
     *
     * @param array<string, mixed>|null $before The stored values before the write.
     * @param array<string, bool|array{mixed}> $restore Per property Tide filled: false or true for
     *     uninitialised or null before, or the value it held, in a one-item list.
     */
    private function undoneBy(ModelMap $map, Connection $connection, ?array $before, array $restore = []): void
    {
        $connection->afterRollback(function () use ($map, $before, $restore): void {
            $this->storedValues = $before;
            foreach ($restore as $name => $was) {
                match (true) {
                    $was === false => $map->forget($this, $name),
                    $was === true => $map->assign($this, $name, null),
                    default => $map->assign($this, $name, $was[0]),
                };
            }
        });
    }

    public function refresh(): static
    {
        $map = ModelMap::of(static::class);
        $key = $map->requireKey();
        if ($this->storedValues === null) {
            throw new \LogicException(static::class
            . '::refresh() needs a stored model; save it before refreshing it.');
        }
        $fresh = static::query()->find($this->storedValues[$key->property]);
        if ($fresh === null) {
            throw new RowGone(static::class . '::refresh() found no row; discard this model and read an existing row.');
        }
        $values = $map->values($fresh);
        foreach ($map->fields as $name => $_) {
            $map->assign($this, $name, $values[$name]);
        }
        $this->storedValues = $fresh->storedValues;
        foreach ($map->relations as $name => $_) {
            unset($this->{$name});
        }
        return $this;
    }

    public function stored(): bool
    {
        return $this->storedValues !== null;
    }

    public function related(string $relation): ModelQuery
    {
        $map = ModelMap::of(static::class);
        $map->requireKey();
        if (!$this->stored()) {
            throw new \LogicException(static::class . '::related() needs a stored model; save it first.');
        }
        return Relations::query(new ModelQuery($map, Tide::database($map->database)), $this, $map->relation($relation));
    }

    public function jsonSerialize(): array
    {
        $map = ModelMap::of(static::class);
        $json = [];
        foreach ($map->visibleValues($this) as $name => $value) {
            $json[$name] = $value instanceof \DateTimeInterface ? $this->serializeDate($value) : $value;
        }
        return $json;
    }

    /**
     * How a date of this model is written in JSON: ISO 8601 with its offset unless the model says
     * otherwise, as an API that gives times as the database holds them does:
     *
     *     protected function serializeDate(\DateTimeInterface $date): string
     *     {
     *         return $date->format('Y-m-d H:i:s');
     *     }
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format(\DateTimeInterface::ATOM);
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide;

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
        return new ModelQuery($map, Tide::database($map->database)->current());
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
        Relations::load($map, Tide::database($map->database)->current(), $models, Relations::tree($map, $relations));
    }

    public function save(): static
    {
        $map = ModelMap::of(static::class);
        $key = $map->requireKey();
        $values = $map->values($this);
        if ($this->storedValues === null) {
            if ($values === []) {
                throw new \LogicException(static::class
                . '::save() has no initialized column; give at least one column a value before saving.');
            }
            $givenKey = array_key_exists($key->property, $values);
            if (!$givenKey && $key->type === 'string') {
                throw new \LogicException(
                    static::class . "::\${$key->property} is a string key; give it a value before save().",
                );
            }
            $written = [];
            foreach ($values as $name => $value) {
                $field = $map->fields[$name];
                $written[$field->column] = $field->toColumn($value);
            }
            $id = Tide::database($map->database)->current()->table($map->table)->insert($written);
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
        Tide::database($map->database)
            ->current()
            ->table($map->table)
            ->where($key->column, $this->storedValues[$key->property])
            ->update($written);
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
        Tide::database($map->database)
            ->current()
            ->table($map->table)
            ->where($key->column, $this->storedValues[$key->property])
            ->delete();
        $this->storedValues = null;
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
        $values = get_mangled_object_vars($fresh);
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
        return Relations::query($map, Tide::database($map->database)->current(), $this, $map->relation($relation));
    }

    public function jsonSerialize(): array
    {
        $map = ModelMap::of(static::class);
        $values = get_object_vars($this);
        $json = [];
        foreach ($map->properties as $name) {
            if (isset($map->hidden[$name]) || !array_key_exists($name, $values)) {
                continue;
            }
            $value = $values[$name];
            $json[$name] = $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : $value;
        }
        return $json;
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Field;

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
            [$written, $givenKey, $stamps, $before] = $this->insertion($map, $key, $values);
            $connection = Tide::database($map->database)->current();
            $id = $connection->table($map->table)->insert($written);
            $this->inserted($map, $key, $connection, $givenKey, $stamps, $before, $id);
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
        $restore = [];
        if ($stamp !== null) {
            $restore[$stamp->property] = $map->initialized($this, $stamp->property)
                ? [$map->value($this, $stamp->property)]
                : false;
            if ($restore[$stamp->property] === false && $map->hooked($stamp->property)) {
                // Checked before the statement, so that nothing is written that could not be taken back.
                throw new \LogicException(
                    static::class
                    . "::\${$stamp->property} has a hook and no value, and Tide would stamp it and have to take it back if the transaction is rolled back, which PHP cannot do for a hooked property; give it a value or declare it without hooks.",
                );
            }
        }
        $connection = Tide::database($map->database)->current();
        $connection->table($map->table)->where($key->column, $this->storedValues[$key->property])->update($written);
        $this->undoneBy($map, $connection, $this->storedValues, $restore);
        if ($stamp !== null) {
            $map->assign($this, $stamp->property, $changed[$stamp->property]);
        }
        $this->storedValues = array_replace($this->storedValues, $changed);
        foreach ($map->relations as $name => $relation) {
            $columns = match (true) {
                $relation->attribute instanceof BelongsTo => [$relation->attribute->foreignKey],
                $relation->attribute instanceof MorphTo => [$relation->attribute->type, $relation->attribute->id],
                default => [],
            };
            if (array_intersect_key($written, array_flip($columns)) === []) {
                continue;
            }
            // Loaded for what the row pointed to before, it would answer with the old row.
            unset($this->{$name});
        }
        return $this;
    }

    /**
     * Points a BelongsTo or MorphTo at another model, or at none with null: its
     * key column, and a MorphTo's type column, take the model's key and morph
     * name, and the relation holds the model. save() writes the columns.
     *
     *     $comment->associate('commentable', $post)->save();
     */
    public function associate(string $relation, ?self $model): static
    {
        $map = ModelMap::of(static::class);
        $declared = $map->relation($relation);
        $attribute = $declared->attribute;
        if (!$attribute instanceof BelongsTo && !$attribute instanceof MorphTo) {
            throw new \LogicException(
                "{$declared->where} is not a BelongsTo or a MorphTo; associate() points this model at its owner, so save the related model with this one's key instead.",
            );
        }
        $columns = $attribute instanceof MorphTo ? [$attribute->type, $attribute->id] : [$attribute->foreignKey];
        if ($model === null) {
            foreach ($columns as $column) {
                if (!$map->columns[$column]->nullable) {
                    throw new \LogicException(
                        "{$declared->where} cannot point at none: {$map->columns[$column]->property} is not nullable.",
                    );
                }
            }
            if (!$declared->nullable) {
                throw new \LogicException("{$declared->where} cannot point at none; declare it nullable.");
            }
            foreach ($columns as $column) {
                $map->assign($this, $map->columns[$column]->property, null);
            }
            $this->{$relation} = null;
            return $this;
        }
        if ($attribute instanceof MorphTo) {
            $target = $declared->morphTargets()[ModelMap::of($model::class)->morph] ?? null;
            if ($target === null || $target->class !== $model::class) {
                throw new \LogicException(
                    "{$declared->where} cannot point at a "
                    . $model::class
                    . '; it belongs to one of '
                    . implode(', ', $declared->targets)
                    . '.',
                );
            }
            $owner = $target->requireKey();
            $local = $map->columns[$attribute->id];
        } else {
            [$related, $local, $owner] = $declared->keys($map);
            if ($model::class !== $related->class) {
                throw new \LogicException(
                    "{$declared->where} cannot point at a " . $model::class . "; it belongs to a {$related->class}.",
                );
            }
            $target = $related;
        }
        if (!$model->stored() || !$target->initialized($model, $owner->property)) {
            throw new \LogicException(
                "{$declared->where} can point only at a stored "
                . $model::class
                . " with its {$owner->property}; save it first.",
            );
        }
        // Every check is made before anything is assigned: a refused association leaves the model as it was.
        if ($attribute instanceof MorphTo) {
            $map->assign($this, $map->columns[$attribute->type]->property, $target->morph);
        }
        $map->assign($this, $local->property, $target->value($model, $owner->property));
        $this->{$relation} = $model;
        return $this;
    }

    /**
     * Links this model to related models of a many-to-many relation, plain or
     * polymorphic, by their keys; a link already there is left as it is.
     *
     *     $post->attach('tags', [$php->id]);
     *
     * @param list<int|string> $ids
     */
    public function attach(string $relation, array $ids): static
    {
        Pivots::change($this, $relation, 'attach', $ids);
        return $this;
    }

    /**
     * Removes the links of a many-to-many relation to these related models, and no other.
     *
     * @param list<int|string> $ids
     */
    public function detach(string $relation, array $ids): static
    {
        Pivots::change($this, $relation, 'detach', $ids);
        return $this;
    }

    /**
     * Makes a many-to-many relation's links exactly these, in one transaction:
     * the missing are added and the others removed. sync('tags', []) removes them all.
     *
     * @param list<int|string> $ids
     */
    public function sync(string $relation, array $ids): static
    {
        Pivots::change($this, $relation, 'sync', $ids);
        return $this;
    }

    /**
     * What a #[MorphTo] type column holds for this model: its table's name, or #[Table(morph: ...)].
     */
    public static function morphName(): string
    {
        return ModelMap::of(static::class)->morph;
    }

    /**
     * What an insert of this model writes, and what Tide fills in for it.
     *
     * @param array<string, mixed> $values The model's initialised columns, by property.
     * @return array{array<string, mixed>, bool, array<string, mixed>, array<string, bool>}
     *     The columns to write, whether the key was given, the times Tide keeps, and whether each was set before.
     */
    private function insertion(ModelMap $map, Field $key, array $values): array
    {
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
        // What Tide fills in, it takes back if the transaction is rolled back, and PHP cannot make a hooked
        // property uninitialised again: refused before anything is written, not found out on a retry.
        foreach ([...($givenKey ? [] : [$key->property => false]), ...$before] as $name => $wasSet) {
            if (!$wasSet && $map->hooked($name)) {
                throw new \LogicException(
                    static::class
                    . "::\${$name} has a hook, and Tide fills it in on insert and must be able to take it back if the transaction is rolled back, which PHP cannot do for a hooked property; give it a value before save(), or declare it without hooks.",
                );
            }
        }
        $written = [];
        foreach ($values as $name => $value) {
            // Only what has a value is written: an uninitialised property is left to the database's default, and
            // null is written as NULL, so that the model says what the row holds.
            $field = $map->fields[$name];
            $written[$field->column] = $field->toColumn($value);
        }
        if ($written === []) {
            throw new \LogicException(static::class
            . '::save() has no value to write; give at least one column a value before saving.');
        }
        return [$written, $givenKey, $stamps, $before];
    }

    /**
     * The model after its row was inserted: its times and key filled in, stored, and
     * made new again if the transaction is rolled back.
     *
     * @param array<string, mixed> $stamps
     * @param array<string, bool> $before
     */
    private function inserted(
        ModelMap $map,
        Field $key,
        Connection $connection,
        bool $givenKey,
        array $stamps,
        array $before,
        int|string|null $id,
    ): void {
        // Undone, the key the database gave and the times Tide wrote are forgotten too.
        $forget = $givenKey ? [] : [$key->property => false];
        $this->undoneBy($map, $connection, null, [...$forget, ...$before]);
        foreach ($stamps as $name => $value) {
            $map->assign($this, $name, $value);
        }
        if (!$givenKey) {
            $map->assign($this, $key->property, $key->fromColumn($id));
        }
        $stored = [];
        foreach ($map->values($this) as $name => $value) {
            $stored[$name] = $map->snapshot($map->fields[$name], $value);
        }
        $this->storedValues = $stored;
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

    /**
     * @return ModelQuery<Model>
     */
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

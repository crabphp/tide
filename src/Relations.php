<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Query;

/**
 * Explicit, bounded relation loading and correlated relation conditions.
 *
 * @internal
 */
final class Relations
{
    private const BATCH = 1_000;

    /**
     * @param list<string|\Closure> $relations
     * @param array<string, array{constraints: list<\Closure>, nested: array}> $tree
     * @return array<string, array{constraints: list<\Closure>, nested: array}>
     */
    public static function tree(ModelMap $map, array $relations, array $tree = []): array
    {
        $previous = null;
        foreach ($relations as $relation) {
            if ($relation instanceof \Closure) {
                if ($previous === null) {
                    throw new \LogicException(
                        "{$map->class} has a relation constraint without a name; write with('relation', fn (ModelQuery \$query) => ...).",
                    );
                }
                $node = &$tree;
                foreach (explode('.', $previous) as $part) {
                    $entry = &$node[$part];
                    $node = &$entry['nested'];
                }
                $entry['constraints'][] = $relation;
                unset($node, $entry);
                $previous = null;
                continue;
            }
            $parts = explode('.', $relation);
            $map->relation($parts[0]);
            $node = &$tree;
            foreach ($parts as $part) {
                if ($part === '') {
                    throw new \LogicException(
                        "{$map->class} has an empty relation name; write a declared relation or a dotted path such as chapters.notes.",
                    );
                }
                $node[$part] ??= ['constraints' => [], 'nested' => []];
                $node = &$node[$part]['nested'];
            }
            unset($node);
            $previous = $relation;
        }
        return $tree;
    }

    /**
     * @param list<Model> $models
     * @param array<string, array{constraints: list<\Closure>, nested: array}> $tree
     */
    public static function load(ModelQuery $parent, array $models, array $tree): void
    {
        if ($models === []) {
            return;
        }
        $parent->assertCurrent();
        $map = $parent->map;
        foreach ($tree as $name => $node) {
            $relation = $map->relation($name);
            if ($relation->attribute instanceof MorphTo) {
                self::loadMorphTo($parent, $models, $relation, $node);
                continue;
            }
            [$related, $local, $foreign] = $relation->keys($map);
            $query = self::relatedQuery($parent, $related);
            $morph = $relation->morphType($map);
            if ($morph !== null) {
                // Only the rows whose type column names this model: a video's comment 7 is not post 7's.
                $query->where($query->qualifier . '.' . $morph[0], $morph[1]);
            }
            foreach ($node['constraints'] as $constraint) {
                $query->constrain($constraint);
            }
            $query->prepareRelation($relation);
            // Validate nested names even when this particular relation has no rows.
            self::validate($related, $node['nested']);
            $keys = [];
            $parentKeys = [];
            foreach ($models as $index => $model) {
                $key = self::value($map, $model, $local->property, $relation);
                $parentKeys[$index] = $key;
                if ($key !== null) {
                    $keys[$key] = $key;
                }
            }
            $groups = [];
            $objects = [];
            $manyToMany = $relation->attribute instanceof BelongsToMany;
            $single =
                $relation->attribute instanceof HasOne
                || $relation->attribute instanceof BelongsTo
                || $relation->attribute instanceof MorphOne;
            $pivotAlias = 'tide_pivot';
            while (isset($related->columns[$pivotAlias])) {
                $pivotAlias .= '_';
            }
            if ($manyToMany) {
                self::pivot($query, $relation, $foreign->column);
                $query
                    ->builder()
                    ->addSelect($relation->attribute->pivot . '.' . $relation->attribute->key . ' as ' . $pivotAlias);
                $match = $relation->attribute->pivot . '.' . $relation->attribute->key;
            } else {
                $match = $related->table . '.' . $foreign->column;
            }
            foreach (array_chunk(array_values($keys), self::BATCH) as $part) {
                $batch = clone $query;
                $batch->whereIn($match, $part);
                $rows = $batch->builder()->get();
                foreach ($rows as $row) {
                    $group = $manyToMany
                        ? $local->fromColumn($row[$pivotAlias])
                        : $foreign->fromColumn($row[$foreign->column]);
                    if ($single && isset($groups[$group])) {
                        continue;
                    }
                    $identity = $related->key === null ? null : $related->key->fromColumn($row[$related->key->column]);
                    $model = $identity === null
                        ? $query->hydrate($row)
                        : ($objects[$identity] ??= $query->hydrate($row));
                    $groups[$group][] = $model;
                    if ($identity === null) {
                        $objects[] = $model;
                    }
                }
            }
            $loaded = [];
            foreach ($models as $index => $model) {
                $key = $parentKeys[$index];
                $matches = $key === null ? [] : $groups[$key] ?? [];
                if ($single) {
                    $value = $matches[0] ?? null;
                    if ($value === null && !$relation->nullable) {
                        throw new \UnexpectedValueException(
                            "{$relation->where} has no related row; declare the relation nullable or store a matching related row.",
                        );
                    }
                    $model->{$name} = $value;
                    if ($value !== null) {
                        $loaded[spl_object_id($value)] = $value;
                    }
                } else {
                    $model->{$name} = $matches;
                    foreach ($matches as $value) {
                        $loaded[spl_object_id($value)] = $value;
                    }
                }
            }
            $query->loadRelations(array_values($loaded));
            self::load($query, array_values($loaded), $node['nested']);
        }
    }

    /**
     * Loads a MorphTo: the models are grouped by their type column, and each
     * model it may belong to is read with one query per batch of ids.
     *
     * @param list<Model> $models
     * @param array{constraints: list<\Closure>, nested: array} $node
     */
    private static function loadMorphTo(ModelQuery $parent, array $models, Relation $relation, array $node): void
    {
        $map = $parent->map;
        /** @var MorphTo $attribute */
        $attribute = $relation->attribute;
        $types = $map->columns[$attribute->type];
        $ids = $map->columns[$attribute->id];
        $wanted = [];
        $pointers = [];
        foreach ($models as $index => $model) {
            $type = self::value($map, $model, $types->property, $relation);
            $id = self::value($map, $model, $ids->property, $relation);
            $pointers[$index] = $type === null || $id === null ? null : [(string) $type, $id];
            if ($pointers[$index] !== null) {
                $wanted[(string) $type][$id] = $id;
            }
        }
        $found = [];
        foreach ($wanted as $morph => $keys) {
            $related = $relation->morphTarget($morph);
            $key = $related->requireKey();
            $query = self::relatedQuery($parent, $related);
            foreach ($node['constraints'] as $constraint) {
                $query->constrain($constraint);
            }
            $query->prepareRelation($relation);
            self::validate($related, $node['nested']);
            $objects = [];
            foreach (array_chunk(array_values($keys), self::BATCH) as $part) {
                $batch = clone $query;
                $batch->whereIn($related->table . '.' . $key->column, $part);
                $rows = $batch->builder()->get();
                foreach ($rows as $row) {
                    $identity = $key->fromColumn($row[$key->column]);
                    $objects[$identity] ??= $query->hydrate($row);
                }
            }
            $found[$morph] = $objects;
            $query->loadRelations(array_values($objects));
            self::load($query, array_values($objects), $node['nested']);
        }
        foreach ($models as $index => $model) {
            $pointer = $pointers[$index];
            $value = $pointer === null ? null : $found[$pointer[0]][$pointer[1]] ?? null;
            if ($value === null && !$relation->nullable) {
                throw new \UnexpectedValueException(
                    "{$relation->where} has no related row; declare the relation nullable or store a matching related row.",
                );
            }
            $model->{$relation->property} = $value;
        }
    }

    public static function query(ModelQuery $parent, Model $model, Relation $relation): ModelQuery
    {
        [$related, $local, $foreign] = $relation->keys($parent->map);
        $query = self::relatedQuery($parent, $related);
        $key = self::value($parent->map, $model, $local->property, $relation);
        if ($relation->attribute instanceof BelongsToMany) {
            self::pivot($query, $relation, $foreign->column);
            $match = $relation->attribute->pivot . '.' . $relation->attribute->key;
        } else {
            $match = $related->table . '.' . $foreign->column;
        }
        $morph = $relation->morphType($parent->map);
        $query->scope($match, $key, $morph === null ? [] : [$related->table . '.' . $morph[0] => $morph[1]]);
        return $query;
    }

    public static function exists(
        ModelQuery $parent,
        string $path,
        ?\Closure $constraint,
        int $depth = 0,
        array $ancestors = [],
    ): Query {
        [$name, $rest] = array_pad(explode('.', $path, 2), 2, null);
        $relation = $parent->map->relation($name);
        if ($relation->attribute instanceof MorphTo) {
            throw new \LogicException(
                "{$relation->where} belongs to one of several models, so whereHas() and whereDoesntHave() cannot follow it; condition its type and id columns, or load it with with().",
            );
        }
        [$related, $local, $foreign] = $relation->keys($parent->map);
        if ($related->database !== $parent->map->database) {
            throw new \LogicException(
                "{$relation->where} belongs to another database; load it with with() instead of whereHas() or whereDoesntHave().",
            );
        }
        $ancestors[] = $parent->qualifier;
        $alias = null;
        if (in_array($related->table, $ancestors, true)) {
            do {
                $alias = 'tide_related_' . $depth++;
            } while (in_array($alias, $ancestors, true));
        }
        $query = new ModelQuery($related, $parent->database, $parent->connection, $alias);
        if ($rest === null && $constraint !== null) {
            $query->constrain($constraint);
        }
        if ($relation->attribute instanceof BelongsToMany) {
            self::pivot($query, $relation, $foreign->column);
            $match = $relation->attribute->pivot . '.' . $relation->attribute->key;
        } else {
            $match = $query->qualifier . '.' . $foreign->column;
        }
        $query->whereColumn($match, '=', $parent->qualifier . '.' . $local->column);
        $morph = $relation->morphType($parent->map);
        if ($morph !== null) {
            $query->where($query->qualifier . '.' . $morph[0], $morph[1]);
        }
        if ($rest !== null) {
            $query->builder()->whereExists(self::exists($query, $rest, $constraint, $depth + 1, $ancestors));
        }
        return $query->builder();
    }

    private static function pivot(ModelQuery $query, Relation $relation, string $column): void
    {
        $pivot = $relation->attribute;
        $query->builder()->join(
            $pivot->pivot,
            $pivot->pivot . '.' . $pivot->relatedKey,
            '=',
            $query->qualifier . '.' . $column,
        );
    }

    private static function relatedQuery(ModelQuery $parent, ModelMap $related): ModelQuery
    {
        $parent->assertCurrent();
        if ($parent->map->database === $related->database) {
            return new ModelQuery($related, $parent->database, $parent->connection);
        }
        return new ModelQuery($related, Tide::database($related->database));
    }

    private static function value(ModelMap $map, Model $model, string $property, Relation $relation): int|string|null
    {
        if (!$map->initialized($model, $property)) {
            throw new \LogicException(
                "{$relation->where} needs the uninitialized property \${$property}; refresh() the model before loading the relation.",
            );
        }
        return $map->value($model, $property);
    }

    private static function validate(ModelMap $map, array $tree): void
    {
        foreach ($tree as $name => $node) {
            $relation = $map->relation($name);
            if ($node['nested'] === []) {
                continue;
            }
            // Past a MorphTo, a nested relation is read for every model it may belong to, so each must declare it.
            $targets = $relation->attribute instanceof MorphTo
                ? $relation->morphTargets()
                : [ModelMap::of($relation->related)];
            foreach ($targets as $target) {
                self::validate($target, $node['nested']);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Connection;
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
    public static function load(ModelMap $map, Connection $connection, array $models, array $tree): void
    {
        if ($models === []) {
            return;
        }
        foreach ($tree as $name => $node) {
            $relation = $map->relation($name);
            [$related, $local, $foreign] = $relation->keys($map);
            $relatedConnection = self::connection($map, $related, $connection);
            $query = new ModelQuery($related, $relatedConnection);
            foreach ($node['constraints'] as $constraint) {
                $query->constrain($constraint);
            }
            $query->prepareRelation($relation);
            // Validate nested names even when this particular relation has no rows.
            self::validate($related, $node['nested']);
            $keys = [];
            $parentKeys = [];
            foreach ($models as $index => $model) {
                $key = self::value($model, $local->property, $relation);
                $parentKeys[$index] = $key;
                if ($key !== null) {
                    $keys[$key] = $key;
                }
            }
            $groups = [];
            $objects = [];
            $manyToMany = $relation->attribute instanceof BelongsToMany;
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
                foreach ($batch->builder()->get() as $row) {
                    $identity = $related->key === null ? null : $related->key->fromColumn($row[$related->key->column]);
                    $model = $identity === null
                        ? $related->hydrate($row)
                        : ($objects[$identity] ??= $related->hydrate($row));
                    $group = $manyToMany ? $local->fromColumn($row[$pivotAlias]) : $model->{$foreign->property};
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
                if ($relation->attribute instanceof BelongsTo || $relation->attribute instanceof HasOne) {
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
            self::load($related, $relatedConnection, array_values($loaded), $node['nested']);
        }
    }

    public static function query(ModelMap $parent, Connection $connection, Model $model, Relation $relation): ModelQuery
    {
        [$related, $local, $foreign] = $relation->keys($parent);
        $query = new ModelQuery($related, self::connection($parent, $related, $connection));
        $key = self::value($model, $local->property, $relation);
        if ($relation->attribute instanceof BelongsToMany) {
            self::pivot($query, $relation, $foreign->column);
            $match = $relation->attribute->pivot . '.' . $relation->attribute->key;
        } else {
            $match = $related->table . '.' . $foreign->column;
        }
        return $key === null ? $query->whereIn($match, []) : $query->where($match, $key);
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
        $query = new ModelQuery($related, $parent->connection, $alias);
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

    private static function connection(ModelMap $parent, ModelMap $related, Connection $connection): Connection
    {
        return $parent->database === $related->database ? $connection : Tide::database($related->database)->current();
    }

    private static function value(Model $model, string $property, Relation $relation): int|string|null
    {
        $values = get_object_vars($model);
        if (!array_key_exists($property, $values)) {
            throw new \LogicException(
                "{$relation->where} needs the uninitialized property \${$property}; refresh() the model before loading the relation.",
            );
        }
        return $values[$property];
    }

    private static function validate(ModelMap $map, array $tree): void
    {
        foreach ($tree as $name => $node) {
            $relation = $map->relation($name);
            if ($node['nested'] !== []) {
                self::validate(ModelMap::of($relation->related), $node['nested']);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Field;

/**
 * Writes the links of a many-to-many relation, plain or polymorphic, so that no
 * application repeats a pivot's names and its type column. Each change is one
 * transaction, a savepoint inside another.
 *
 * @internal Reached through Model::attach(), detach() and sync().
 */
final class Pivots
{
    /**
     * @param 'attach'|'detach'|'sync' $how
     * @param list<int|string> $ids The related models' keys.
     */
    public static function change(Model $model, string $name, string $how, array $ids): void
    {
        $map = ModelMap::of($model::class);
        $relation = $map->relation($name);
        $attribute = $relation->attribute;
        if (!$attribute instanceof BelongsToMany) {
            throw new \LogicException(
                "{$relation->where} is not a many-to-many relation; {$how}() changes the links of a pivot, so set a foreign key, or use associate(), for this one.",
            );
        }
        if (!$model->stored()) {
            throw new \LogicException($model::class . "::{$how}() needs a stored model; save it before linking it.");
        }
        [$related, $local, $foreign] = $relation->keys($map);
        if ($related->database !== $map->database) {
            throw new \LogicException(
                "{$relation->where} links models of two databases, whose links cannot be written in one transaction; write the pivot with the query builder.",
            );
        }
        if (!$map->initialized($model, $local->property)) {
            throw new \LogicException(
                "{$relation->where} needs \${$local->property}, which this model did not read; refresh() it before {$how}().",
            );
        }
        $parent = $map->value($model, $local->property);
        $wanted = self::keys($ids, $foreign, $relation->where, $how);
        $fixed = [$attribute->key => $parent];
        if ($relation->pivotMorph !== null) {
            $fixed[$relation->pivotMorph[0]] = ModelMap::of($relation->pivotMorph[1])->morph;
        }
        Tide::database($map->database)
            ->current()
            ->transaction(static function (Connection $db) use (
                $map,
                $attribute,
                $fixed,
                $parent,
                $wanted,
                $how,
            ): void {
                $links = static fn() => $db->table($attribute->pivot)->where($fixed);
                if ($how === 'sync') {
                    // Two syncs of one model wait for each other, so neither writes from what the other replaced.
                    $db->table($map->table)->where($map->requireKey()->column, $parent)->lockForUpdate()->first();
                }
                $held = [];
                foreach ($links()->pluck($attribute->relatedKey) as $id) {
                    $held[(string) $id] = $id;
                }
                $gone = match ($how) {
                    'detach' => array_intersect_key($held, $wanted),
                    'sync' => array_diff_key($held, $wanted),
                    default => [],
                };
                if ($gone !== []) {
                    $links()->whereIn($attribute->relatedKey, array_values($gone))->delete();
                }
                $new = $how === 'detach' ? [] : array_diff_key($wanted, $held);
                if ($new !== []) {
                    $db->table($attribute->pivot)->insertMany(array_map(static fn(int|string $id): array => [
                        ...$fixed,
                        $attribute->relatedKey => $id,
                    ], array_values($new)));
                }
            });
        // Loaded before the change, it would answer with the old links.
        unset($model->{$name});
    }

    /**
     * The keys given, each once, of the related key's type.
     *
     * @param list<mixed> $ids
     * @return array<string, int|string>
     */
    private static function keys(array $ids, Field $key, string $where, string $how): array
    {
        $keys = [];
        foreach ($ids as $id) {
            $fits = $key->type === Field::INT ? is_int($id) : is_string($id);
            if (!$fits) {
                throw new \LogicException(
                    "{$where} {$how}() was given "
                    . get_debug_type($id)
                    . " as a key; give the related models' {$key->type} keys.",
                );
            }
            $keys[(string) $id] = $id;
        }
        return $keys;
    }
}

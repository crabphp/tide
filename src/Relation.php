<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Field;

/**
 * One declared relation. The related map is read only when the relation is used.
 *
 * @internal
 */
final readonly class Relation
{
    /**
     * @param list<class-string<Model>> $targets The models a MorphTo may belong to, from its union type.
     * @param array{string, class-string<Model>}|null $pivotMorph For a MorphToMany or MorphedByMany, read as a
     *     BelongsToMany: the pivot's type column, and the model whose morph name it holds.
     */
    private function __construct(
        public string $property,
        public string $where,
        public string $related,
        public bool $nullable,
        public BelongsTo|HasOne|HasMany|BelongsToMany|MorphTo|MorphOne|MorphMany $attribute,
        public array $targets = [],
        public ?array $pivotMorph = null,
    ) {}

    /**
     * @param class-string<Model> $owner The model the relation is read for, whose name gives the keys left unsaid.
     */
    public static function of(
        \ReflectionProperty $property,
        BelongsTo|HasOne|HasMany|BelongsToMany|MorphTo|MorphOne|MorphMany|MorphToMany|MorphedByMany $attribute,
        string $owner,
    ): self {
        $name = $property->getName();
        $where = $property->getDeclaringClass()->getName() . '::$' . $name;
        if ($property->isPromoted() || $property->hasDefaultValue()) {
            throw new \LogicException(
                "{$where} is a relation; declare it outside the constructor without a default so that reading it unloaded fails.",
            );
        }
        if ($property->isReadOnly()) {
            throw new \LogicException("{$where} is readonly; declare a writable relation so it can be loaded again.");
        }
        if ($property->hasHooks() || $property->isPrivateSet() || $property->isProtectedSet()) {
            throw new \LogicException(
                "{$where} has hooks or restricted setter visibility; declare a plain public relation without hooks or asymmetric visibility so it can be loaded and unloaded.",
            );
        }
        $type = $property->getType();
        if ($attribute instanceof MorphTo) {
            return new self(
                $name,
                $where,
                '',
                $type?->allowsNull() ?? false,
                new MorphTo($attribute->type ?? $name . '_type', $attribute->id ?? $name . '_id'),
                self::targets($type, $where),
            );
        }
        $many =
            $attribute instanceof HasMany
            || $attribute instanceof BelongsToMany
            || $attribute instanceof MorphMany
            || $attribute instanceof MorphToMany
            || $attribute instanceof MorphedByMany;
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
        $typeName = match ($typeName) {
            'self' => $property->getDeclaringClass()->getName(),
            'parent' => $property->getDeclaringClass()->getParentClass()->getName(),
            default => $typeName,
        };
        if (
            !$type instanceof \ReflectionNamedType
            || $many && $typeName !== 'array'
            || !$many && !is_subclass_of($typeName, Model::class)
        ) {
            throw new \LogicException(
                "{$where} has the wrong relation type; declare "
                . (
                    $many
                        ? 'array and name the related Model class in the attribute.'
                        : 'a Model subclass, nullable when a related row may be absent.'
                ),
            );
        }
        $related = $many ? $attribute->related : $typeName;
        if (!is_subclass_of($related, Model::class)) {
            throw new \LogicException(
                "{$where} names {$related}, which is not a model; name a class extending " . Model::class . '.',
            );
        }
        // Many to many through a pivot whose type column says which model a row links: a BelongsToMany whose
        // pivot rows are those of one morph name. Post's tags link taggable_type "posts"; Tag's posts, the same.
        if ($attribute instanceof MorphToMany || $attribute instanceof MorphedByMany) {
            $pivot = $attribute->pivot ?? Names::plural($attribute->name);
            $morphId = $attribute->name . '_id';
            return new self(
                $name,
                $where,
                $related,
                $type->allowsNull(),
                $attribute instanceof MorphToMany
                    ? new BelongsToMany($related, $pivot, $morphId, $attribute->relatedKey ?? Names::key($related))
                    : new BelongsToMany($related, $pivot, $attribute->key ?? Names::key($owner), $morphId),
                pivotMorph: [$attribute->name . '_type', $attribute instanceof MorphToMany ? $owner : $related],
            );
        }
        // What the attribute leaves out is named after the models, so the rest reads one complete declaration.
        $attribute = match (true) {
            $attribute instanceof BelongsTo => new BelongsTo(
                $attribute->foreignKey ?? $name . '_id',
                $attribute->ownerKey,
            ),
            $attribute instanceof HasOne => new HasOne(
                $attribute->foreignKey ?? Names::key($owner),
                $attribute->localKey,
            ),
            $attribute instanceof HasMany => new HasMany(
                $related,
                $attribute->foreignKey ?? Names::key($owner),
                $attribute->localKey,
            ),
            // The related model's MorphTo is found when the relation is first used, once both models can be read.
            $attribute instanceof MorphOne, $attribute instanceof MorphMany => $attribute,
            default => new BelongsToMany(
                $related,
                $attribute->pivot ?? Names::pivot($owner, $related),
                $attribute->key ?? Names::key($owner),
                $attribute->relatedKey ?? Names::key($related),
            ),
        };
        return new self($name, $where, $related, $type->allowsNull(), $attribute);
    }

    public function checkLocal(ModelMap $map): void
    {
        if ($this->attribute instanceof BelongsTo) {
            $this->field($map, $this->attribute->foreignKey);
        }
        if ($this->attribute instanceof MorphTo) {
            $type = $map->columns[$this->attribute->type] ?? null;
            if ($type === null || $type->type !== Field::STRING) {
                throw new \LogicException(
                    "{$this->where} needs the column \"{$this->attribute->type}\" on {$map->class} for the related model's morph name; declare it as a string property, or name the type column in #[MorphTo(type: ...)].",
                );
            }
            $this->field($map, $this->attribute->id);
        }
    }

    /**
     * The columns of the model the relation is read for that its rows are matched by.
     *
     * @return list<string>
     */
    public function localColumns(ModelMap $map): array
    {
        if ($this->attribute instanceof MorphTo) {
            return [$this->attribute->type, $this->attribute->id];
        }
        return [$this->keys($map)[1]->column];
    }

    /**
     * @return array{ModelMap, Field, Field} Related map, parent field, related field.
     */
    public function keys(ModelMap $parent): array
    {
        $attribute = $this->attribute;
        if ($attribute instanceof MorphTo) {
            throw new \LogicException(
                "{$this->where} belongs to one of several models; load it with with('{$this->property}') or Model::load().",
            );
        }
        $related = ModelMap::of($this->related);
        if ($attribute instanceof BelongsTo) {
            return [
                $related,
                $this->field($parent, $attribute->foreignKey),
                $attribute->ownerKey === null ? $related->requireKey() : $this->field($related, $attribute->ownerKey),
            ];
        }
        if ($attribute instanceof BelongsToMany) {
            return [$related, $parent->requireKey(), $related->requireKey()];
        }
        if ($attribute instanceof MorphOne || $attribute instanceof MorphMany) {
            return [
                $related,
                $attribute->localKey === null ? $parent->requireKey() : $this->field($parent, $attribute->localKey),
                $this->field($related, $this->pair($parent, $related)->id),
            ];
        }
        return [
            $related,
            $attribute->localKey === null ? $parent->requireKey() : $this->field($parent, $attribute->localKey),
            $this->field($related, $attribute->foreignKey),
        ];
    }

    /**
     * For a MorphOne or MorphMany, the related model's type column and the
     * morph name it holds for the parent; null for any other relation.
     *
     * @return array{string, string}|null
     */
    public function morphType(ModelMap $parent): ?array
    {
        if (!$this->attribute instanceof MorphOne && !$this->attribute instanceof MorphMany) {
            return null;
        }
        return [$this->pair($parent, ModelMap::of($this->related))->type, $parent->morph];
    }

    /**
     * For a many-to-many morph, the pivot's type column, with its table, and the morph name its rows hold.
     *
     * @return array{string, string}|null
     */
    public function pivotType(): ?array
    {
        if ($this->pivotMorph === null) {
            return null;
        }
        /** @var BelongsToMany $attribute */
        $attribute = $this->attribute;
        return [$attribute->pivot . '.' . $this->pivotMorph[0], ModelMap::of($this->pivotMorph[1])->morph];
    }

    /**
     * For a MorphTo, the models it may belong to by the morph name its type column holds for each.
     *
     * @return array<string, ModelMap>
     */
    public function morphTargets(): array
    {
        $maps = [];
        foreach ($this->targets as $class) {
            $map = ModelMap::of($class);
            $map->requireKey();
            if (isset($maps[$map->morph])) {
                throw new \LogicException(
                    "{$this->where} lists {$maps[$map->morph]->class} and {$class}, which share the morph name \"{$map->morph}\"; give one another with #[Table(morph: '...')].",
                );
            }
            $maps[$map->morph] = $map;
        }
        return $maps;
    }

    /**
     * The model of one morph name a MorphTo's type column holds.
     */
    public function morphTarget(string $morph): ModelMap
    {
        return $this->morphTargets()[$morph] ?? throw new \UnexpectedValueException(
            "{$this->where} has the type \"{$morph}\", which is none of its models' morph names ("
            . implode(', ', array_keys($this->morphTargets()))
            . '); add its model to the union type, or correct the row.',
        );
    }

    /**
     * The related model's MorphTo that a MorphOne or MorphMany pairs with: the one named, or the one that lists the parent.
     */
    private function pair(ModelMap $parent, ModelMap $related): MorphTo
    {
        /** @var MorphOne|MorphMany $attribute */
        $attribute = $this->attribute;
        $found = [];
        foreach ($related->relations as $name => $relation) {
            if (
                !$relation->attribute instanceof MorphTo
                || $attribute->relation !== null && $attribute->relation !== $name
            ) {
                continue;
            }
            if (!in_array($parent->class, $relation->targets, true)) {
                if ($attribute->relation !== null) {
                    throw new \LogicException(
                        "{$this->where} pairs with {$relation->where}, which does not list {$parent->class}; add it to that property's union type.",
                    );
                }
                continue;
            }
            $found[$name] = $relation->attribute;
        }
        if (count($found) === 1) {
            return array_values($found)[0];
        }
        throw new \LogicException(
            $found === []
                ? "{$this->where} needs a #[MorphTo] on {$related->class} that lists {$parent->class}; declare one, such as #[MorphTo] public {$parent->class}|... \$owner;."
                : "{$this->where} could pair with "
                . implode(' or ', array_map(
                    static fn(string $name): string => "{$related->class}::\${$name}",
                    array_keys($found),
                ))
                . "; name the one meant: #[MorphMany({$related->class}::class, relation: '"
                . array_key_first($found)
                . "')].",
        );
    }

    /**
     * The models a MorphTo's union type lists.
     *
     * @return list<class-string<Model>>
     */
    private static function targets(?\ReflectionType $type, string $where): array
    {
        $members = match (true) {
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            $type instanceof \ReflectionNamedType => [$type],
            default => [],
        };
        $targets = [];
        foreach ($members as $member) {
            if (!$member instanceof \ReflectionNamedType) {
                $targets = [];
                break;
            }
            if ($member->getName() === 'null') {
                continue;
            }
            if (!is_subclass_of($member->getName(), Model::class)) {
                $targets = [];
                break;
            }
            $targets[] = $member->getName();
        }
        if ($targets === []) {
            throw new \LogicException(
                "{$where} is a MorphTo; declare the models it may belong to as its type, such as Post|Video, nullable when it may belong to none.",
            );
        }
        return $targets;
    }

    private function field(ModelMap $map, string $column): Field
    {
        $field = $map->columns[$column] ?? null;
        if ($field === null || !in_array($field->type, [Field::INT, Field::STRING], true)) {
            throw new \LogicException(
                "{$this->where} needs the column \"{$column}\" on {$map->class}; declare it as an int or string property, or name the relation's real key in its attribute.",
            );
        }
        return $field;
    }
}

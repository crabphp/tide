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
    private function __construct(
        public string $property,
        public string $where,
        public string $related,
        public bool $nullable,
        public BelongsTo|HasOne|HasMany|BelongsToMany $attribute,
    ) {}

    public static function of(\ReflectionProperty $property, BelongsTo|HasOne|HasMany|BelongsToMany $attribute): self
    {
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
        $type = $property->getType();
        $many = $attribute instanceof HasMany || $attribute instanceof BelongsToMany;
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
        return new self($name, $where, $related, $type->allowsNull(), $attribute);
    }

    public function checkLocal(ModelMap $map): void
    {
        if ($this->attribute instanceof BelongsTo) {
            $this->field($map, $this->attribute->foreignKey);
        }
    }

    /**
     * @return array{ModelMap, Field, Field} Related map, parent field, related field.
     */
    public function keys(ModelMap $parent): array
    {
        $related = ModelMap::of($this->related);
        $attribute = $this->attribute;
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
        return [
            $related,
            $attribute->localKey === null ? $parent->requireKey() : $this->field($parent, $attribute->localKey),
            $this->field($related, $attribute->foreignKey),
        ];
    }

    private function field(ModelMap $map, string $column): Field
    {
        $field = $map->columns[$column] ?? null;
        if ($field === null || !in_array($field->type, [Field::INT, Field::STRING], true)) {
            throw new \LogicException(
                "{$this->where} needs the column \"{$column}\" on {$map->class}; declare it as an int or string property, using #[ColumnName] when its name differs.",
            );
        }
        return $field;
    }
}

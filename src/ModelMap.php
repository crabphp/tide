<?php

declare(strict_types=1);

namespace Crab\Tide;

use Crab\Contracts\Database\Cast;
use Crab\Contracts\Database\Field;
use Crab\Database\ColumnName;

/**
 * A model's declaration, read once. Only code metadata is cached.
 *
 * @internal
 */
final class ModelMap
{
    /**
     * @var array<class-string<Model>, self>
     */
    private static array $maps = [];
    private readonly \ReflectionClass $reflection;
    private readonly \Closure $remember;
    /**
     * @var array<string, \ReflectionProperty>
     */
    private readonly array $setters;
    /**
     * @var array<string, \ReflectionProperty> Mapped public properties only.
     */
    private readonly array $readers;
    /**
     * @var array<string, string>
     */
    private readonly array $missing;

    /**
     * @param class-string<Model> $class
     * @param array<string, Field> $fields By property, in declaration order.
     * @param array<string, Field> $columns By column.
     * @param array<string, Relation> $relations
     * @param list<string> $properties
     * @param array<string, true> $hidden
     * @param array<string, \ReflectionProperty> $setters
     * @param array<string, \ReflectionProperty> $readers
     */
    private function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly ?string $database,
        public readonly ?Field $key,
        public readonly array $fields,
        public readonly array $columns,
        public readonly array $relations,
        public readonly array $properties,
        public readonly array $hidden,
        array $setters,
        array $readers,
        public readonly ?Field $createdAt = null,
        public readonly ?Field $updatedAt = null,
    ) {
        $this->reflection = new \ReflectionClass($class);
        $this->setters = $setters;
        $this->readers = $readers;
        $this->remember = \Closure::bind(
            static function (Model $model, array $values): void {
                $model->storedValues = $values;
            },
            null,
            Model::class,
        );
        $missing = [];
        foreach ($fields as $name => $field) {
            $missing[$name] = "{$class}::\${$name} reads the column \"{$field->column}\", which the query did not read; a model is a whole row. Select every model column, or use a record for a projection.";
        }
        $this->missing = $missing;
    }

    public static function of(string $class): self
    {
        return self::$maps[$class] ??= self::inspect($class);
    }

    public function requireKey(): Field
    {
        return (
            $this->key ?? throw new \LogicException(
                "{$this->class} has no key property; declare public int \$id; and map it to the table's key column with #[ColumnName] when its name differs.",
            )
        );
    }

    public function relation(string $name): Relation
    {
        return (
            $this->relations[$name] ?? throw new \LogicException(
                "{$this->class} has no relation \"{$name}\"; use one of its declared relations: "
                . (implode(', ', array_keys($this->relations)) ?: '(none; declare a relation property)')
                . '.',
            )
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): Model
    {
        $model = $this->reflection->newInstanceWithoutConstructor();
        $stored = [];
        foreach ($this->fields as $name => $field) {
            if (!array_key_exists($field->column, $row)) {
                throw new \LogicException($this->missing[$name]);
            }
            $value = $field->fromColumn($row[$field->column]);
            $this->assign($model, $name, $value);
            $stored[$name] = $this->snapshot($field, $value);
        }
        ($this->remember)($model, $stored);
        return $model;
    }

    public function assign(Model $model, string $name, mixed $value): void
    {
        if (isset($this->setters[$name])) {
            $this->setters[$name]->setRawValue($model, $value);
        } else {
            $model->{$name} = $value;
        }
    }

    /**
     * Makes a property uninitialised again, as it was before the database gave it a value.
     */
    public function forget(Model $model, string $name): void
    {
        $forget = function () use ($name): void {
            unset($this->{$name});
        };
        try {
            \Closure::bind($forget, $model, $this->readers[$name]->getDeclaringClass()->getName())();
        } catch (\Error) {
            // A hooked property cannot be unset; it keeps the value the rollback undid.
        }
    }

    public function snapshot(Field $field, mixed $value): mixed
    {
        if ($field->cast !== null && is_object($value)) {
            return $field->toColumn($value);
        }
        return $value instanceof \DateTime ? \DateTimeImmutable::createFromMutable($value) : $value;
    }

    /**
     * Persistence reads only mapped, initialized columns, without evaluating hooks.
     *
     * @return array<string, mixed>
     */
    public function values(Model $model): array
    {
        $values = [];
        foreach ($this->fields as $name => $_) {
            if (!$this->initialized($model, $name)) {
                continue;
            }
            $values[$name] = $this->value($model, $name);
        }
        return $values;
    }

    public function initialized(Model $model, string $name): bool
    {
        return $this->readers[$name]->isInitialized($model);
    }

    public function value(Model $model, string $name): mixed
    {
        return $this->readers[$name]->getRawValue($model);
    }

    /**
     * The application's view, read outside Model's private scope and only after the hidden filter.
     *
     * @return array<string, mixed>
     */
    public function visibleValues(Model $model): array
    {
        $values = [];
        foreach ($this->properties as $name) {
            if (isset($this->hidden[$name]) || !$this->initialized($model, $name)) {
                continue;
            }
            $values[$name] = $model->{$name};
        }
        return $values;
    }

    private static function inspect(string $class): self
    {
        if (!is_subclass_of($class, Model::class)) {
            throw new \LogicException("{$class} cannot be a model; declare a class extending " . Model::class . '.');
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract()) {
            throw new \LogicException("{$class} is abstract; query a concrete model that extends it.");
        }
        // Without #[Table] the table is named after the class: OrderItem reads order_items.
        $attributes = $reflection->getAttributes(Table::class);
        $table = $attributes === [] ? new Table() : $attributes[0]->newInstance();
        $tableName = $table->name ?? Names::table($class);
        $fields = $columns = $relations = $hidden = $setters = $readers = [];
        $properties = [];
        foreach (self::properties($reflection) as $name => $property) {
            if ($property->isStatic()) {
                continue;
            }
            $relationAttributes = array_filter($property->getAttributes(), static fn(\ReflectionAttribute $attribute): bool => in_array(
                $attribute->getName(),
                [BelongsTo::class, HasOne::class, HasMany::class, BelongsToMany::class],
                true,
            ));
            if ($property->isVirtual() && $relationAttributes === []) {
                continue;
            }
            $properties[] = $name;
            $readers[$name] = $property;
            if ($property->getAttributes(Hidden::class) !== []) {
                $hidden[$name] = true;
            }
            $where = "{$class}::\${$name}";
            if (count($relationAttributes) > 1) {
                throw new \LogicException(
                    "{$where} has two relation attributes; declare one relation on the property.",
                );
            }
            if ($relationAttributes !== []) {
                if (
                    $property->getAttributes(ColumnName::class) !== []
                    || $property->getAttributes(Cast::class, \ReflectionAttribute::IS_INSTANCEOF) !== []
                ) {
                    throw new \LogicException(
                        "{$where} is both a relation and a column mapping; remove its cast and #[ColumnName].",
                    );
                }
                $attribute = array_values($relationAttributes)[0]->newInstance();
                $relations[$name] = Relation::of($property, $attribute, $class);
                continue;
            }
            if (
                self::isModel($property)
                && $property->getAttributes(Cast::class, \ReflectionAttribute::IS_INSTANCEOF) === []
            ) {
                // A property that is another model belongs to it, by the column named after the property.
                $relations[$name] = Relation::of($property, new BelongsTo(), $class);
                continue;
            }
            if ($property->isReadOnly() || $reflection->isReadOnly()) {
                throw new \LogicException(
                    "{$where} is readonly; declare a writable public property, because a model changes.",
                );
            }
            $named = $property->getAttributes(ColumnName::class);
            try {
                $column = $named === [] ? $name : $named[0]->newInstance()->name;
            } catch (\InvalidArgumentException $error) {
                throw new \LogicException(
                    "{$where} has an invalid column name; write #[ColumnName('column_name')] with a valid identifier.",
                    previous: $error,
                );
            }
            Table::identifier($column, $where);
            $hasDefault = $property->hasDefaultValue();
            $field = Field::of(
                $property,
                $column,
                $hasDefault,
                $hasDefault ? $property->getDefaultValue() : null,
                'model',
            );
            if (isset($columns[$column])) {
                throw new \LogicException(
                    "{$class} cannot be a model: \${$columns[$column]->property} and \${$name} both map to the column \"{$column}\"; give each property its own column.",
                );
            }
            $fields[$name] = $columns[$column] = $field;
            if ($property->hasHooks() || $property->isPrivateSet() || $property->isProtectedSet()) {
                $setters[$name] = $property;
            }
        }
        $key = $columns[$table->key] ?? null;
        if ($key !== null && (!in_array($key->type, [Field::INT, Field::STRING], true) || $key->nullable)) {
            throw new \LogicException(
                "{$class}::\${$key->property} is the key; declare it as a non-nullable int or string.",
            );
        }
        $map = new self(
            $class,
            $tableName,
            $table->database,
            $key,
            $fields,
            $columns,
            $relations,
            $properties,
            $hidden,
            $setters,
            $readers,
            self::stamp($reflection, $columns, 'created'),
            self::stamp($reflection, $columns, 'updated'),
        );
        foreach ($relations as $relation) {
            $relation->checkLocal($map);
        }
        return $map;
    }

    /**
     * The column #[Timestamps] names for one of its times, on the model or a parent of it.
     *
     * @param array<string, Field> $columns
     */
    private static function stamp(\ReflectionClass $class, array $columns, string $which): ?Field
    {
        $attributes = [];
        for ($declared = $class; $declared !== false && $attributes === []; $declared = $declared->getParentClass()) {
            $attributes = $declared->getAttributes(Timestamps::class);
        }
        if ($attributes === []) {
            // Unsaid, a model keeps the times it declares: created_at and updated_at, as dates.
            $field = $columns[$which . '_at'] ?? null;
            return $field !== null && $field->type === Field::DATE ? $field : null;
        }
        $column = $attributes[0]->newInstance()->{$which};
        if ($column === null) {
            return null;
        }
        $field = $columns[$column] ?? null;
        if ($field === null || $field->type !== Field::DATE) {
            throw new \LogicException(
                "{$class->getName()} has #[Timestamps] for the column \"{$column}\"; declare it as a date property, public ?\\DateTimeImmutable \${$column} = null;, or write #[Timestamps({$which}: null)] when the table has no such column.",
            );
        }
        return $field;
    }

    /**
     * Whether a property's type is another model, which makes it a relation.
     */
    private static function isModel(\ReflectionProperty $property): bool
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() && $type->getName() !== 'self') {
            return false;
        }
        $name = match ($type->getName()) {
            'self', 'static' => $property->getDeclaringClass()->getName(),
            'parent' => (string) $property->getDeclaringClass()->getParentClass()?->getName(),
            default => $type->getName(),
        };
        return is_subclass_of($name, Model::class);
    }

    /**
     * Parents' declarations precede the child's, with overrides in place.
     *
     * @return array<string, \ReflectionProperty>
     */
    private static function properties(\ReflectionClass $class): array
    {
        $parent = $class->getParentClass();
        $properties = $parent === false ? [] : self::properties($parent);
        foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $properties[$property->getName()] = $property;
        }
        return $properties;
    }
}

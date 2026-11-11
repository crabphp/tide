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
    private readonly array $hooks;
    /**
     * @var list<string> Backed getters are evaluated only after every column is assigned.
     */
    private readonly array $getters;
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
     * @param array<string, \ReflectionProperty> $hooks
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
        array $hooks,
        array $getters,
    ) {
        $this->reflection = new \ReflectionClass($class);
        $this->hooks = $hooks;
        $this->getters = $getters;
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
        foreach ($this->getters as $name) {
            $stored[$name] = $this->snapshot($this->fields[$name], $model->{$name});
        }
        ($this->remember)($model, $stored);
        return $model;
    }

    public function assign(Model $model, string $name, mixed $value): void
    {
        if (isset($this->hooks[$name])) {
            $this->hooks[$name]->setRawValue($model, $value);
        } else {
            $model->{$name} = $value;
        }
    }

    public function snapshot(Field $field, mixed $value): mixed
    {
        return $field->cast !== null && is_object($value) ? $field->toColumn($value) : $value;
    }

    /**
     * Called outside Model's scope so only initialized public columns are seen.
     *
     * @return array<string, mixed>
     */
    public function values(Model $model): array
    {
        return array_intersect_key(get_object_vars($model), $this->fields);
    }

    private static function inspect(string $class): self
    {
        if (!is_subclass_of($class, Model::class)) {
            throw new \LogicException("{$class} cannot be a model; declare a class extending " . Model::class . '.');
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract()) {
            throw new \LogicException("{$class} is abstract; query a concrete model with its own #[Table].");
        }
        $attributes = $reflection->getAttributes(Table::class);
        if ($attributes === []) {
            throw new \LogicException("{$class} has no table; declare #[Table('table_name')] on the model itself.");
        }
        $table = $attributes[0]->newInstance();
        $fields = $columns = $relations = $hidden = $hooks = [];
        $properties = $getters = [];
        foreach (self::properties($reflection) as $name => $property) {
            if ($property->isStatic() || $property->isVirtual()) {
                continue;
            }
            $properties[] = $name;
            if ($property->getAttributes(Hidden::class) !== []) {
                $hidden[$name] = true;
            }
            $relationAttributes = array_filter($property->getAttributes(), static fn(\ReflectionAttribute $attribute): bool => in_array(
                $attribute->getName(),
                [BelongsTo::class, HasOne::class, HasMany::class, BelongsToMany::class],
                true,
            ));
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
                $relations[$name] = Relation::of($property, $attribute);
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
            if ($property->hasHook(\PropertyHookType::Get)) {
                $getters[] = $name;
            }
            if ($property->hasHook(\PropertyHookType::Set)) {
                $hooks[$name] = $property;
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
            $table->name,
            $table->database,
            $key,
            $fields,
            $columns,
            $relations,
            $properties,
            $hidden,
            $hooks,
            $getters,
        );
        foreach ($relations as $relation) {
            $relation->checkLocal($map);
        }
        return $map;
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

<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * Makes models for tests and seeders. A factory says once how a plausible
 * model is made; a test changes only what it is about:
 *
 *     final class BookFactory extends Factory
 *     {
 *         protected function definition(int $sequence): Book
 *         {
 *             return new Book("Book {$sequence}");
 *         }
 *     }
 *
 *     $books = BookFactory::new()->count(3)->for('author', $ada)->state(['votes' => 10])->create();
 *     $draft = BookFactory::new()->state(fn (Book $book) => $book->status = Status::Draft)->createOne();
 *     $ada = AuthorFactory::new()->has('books', BookFactory::new()->count(3))->createOne();
 *
 * A factory is never changed: each method returns a new one, so a shared one can be built on.
 *
 * @template T of Model
 */
abstract class Factory
{
    /**
     * @var array<class-string<self>, int> How many models each factory has made, so that a value can be unique.
     */
    private static array $sequences = [];
    private int $count = 1;
    /**
     * @var list<\Closure(T, int): mixed>
     */
    private array $states = [];
    /**
     * @var list<array{string, Factory}> The relations create() fills, with the factory of their models.
     */
    private array $children = [];

    final public function __construct() {}

    public static function new(): static
    {
        return new static();
    }

    /**
     * A new, plausible model, not saved. $sequence counts the models this
     * factory has made, from 1, for values that must differ: "user{$sequence}@example.com".
     *
     * @return T
     */
    abstract protected function definition(int $sequence): Model;

    public function count(int $count): static
    {
        if ($count < 1) {
            throw new \LogicException(static::class . '::count() needs at least 1.');
        }
        $factory = clone $this;
        $factory->count = $count;
        return $factory;
    }

    /**
     * Changes each model after its definition: properties by name, ['votes' => 10],
     * or a closure given the model and its sequence number. States apply in order.
     *
     * @param array<string, mixed>|\Closure(T, int): mixed $state
     */
    public function state(array|\Closure $state): static
    {
        if (is_array($state)) {
            $values = $state;
            $state = static function (Model $model) use ($values): void {
                $map = ModelMap::of($model::class);
                foreach ($values as $name => $value) {
                    if (!isset($map->fields[$name])) {
                        throw new \LogicException(
                            static::class
                            . "::state() names \"{$name}\", which is not one of "
                            . $model::class
                            . "'s columns; name "
                            . implode(', ', array_keys($map->fields))
                            . '.',
                        );
                    }
                    $model->{$name} = $value;
                }
            };
        }
        $factory = clone $this;
        $factory->states[] = $state;
        return $factory;
    }

    /**
     * Each model belongs to this owner, by a BelongsTo or MorphTo relation, as associate() does.
     */
    public function for(string $relation, Model $owner): static
    {
        return $this->state(static fn(Model $model): Model => $model->associate($relation, $owner));
    }

    /**
     * Each model is created with related models of its own, from another
     * factory: a HasMany, HasOne, MorphMany or MorphOne gets their key, and a
     * many-to-many relation links them. The relation holds them afterwards.
     * Only create() writes them; a BelongsTo is for().
     *
     * @template R of Model
     * @param Factory<R> $related
     */
    public function has(string $relation, self $related): static
    {
        $factory = clone $this;
        $factory->children[] = [$relation, $related];
        return $factory;
    }

    /**
     * The models, not saved.
     *
     * @return list<T>
     */
    public function make(): array
    {
        if ($this->children !== []) {
            throw new \LogicException(static::class
            . '::make() cannot give models related ones from has(), which need their keys; call create().');
        }
        return $this->built();
    }

    /**
     * @return list<T>
     */
    private function built(): array
    {
        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $sequence = self::$sequences[static::class] = (self::$sequences[static::class] ?? 0) + 1;
            $model = $this->definition($sequence);
            if ($model->stored()) {
                throw new \LogicException(static::class
                . '::definition() must return a new model; make it with new, without saving.');
            }
            foreach ($this->states as $state) {
                $state($model, $sequence);
            }
            $models[] = $model;
        }
        return $models;
    }

    /**
     * The models, saved.
     *
     * @return list<T>
     */
    public function create(): array
    {
        $models = $this->built();
        foreach ($models as $model) {
            $this->saved($model);
        }
        return $models;
    }

    /**
     * Saves one of this factory's models, then creates its related models from has().
     *
     * @param T $model
     */
    private function saved(Model $model): void
    {
        $model->save();
        foreach ($this->children as [$relation, $factory]) {
            $factory->createFor($model, $relation);
        }
    }

    /**
     * Creates this factory's models as the related models of one parent.
     */
    private function createFor(Model $parent, string $name): void
    {
        $map = ModelMap::of($parent::class);
        $relation = $map->relation($name);
        $attribute = $relation->attribute;
        if ($attribute instanceof BelongsTo || $attribute instanceof MorphTo) {
            throw new \LogicException(
                "{$relation->where} belongs to its owner; make the owner first and give it with for('{$name}', \$owner).",
            );
        }
        [$related, $local, $foreign] = $relation->keys($map);
        $children = $this->built();
        foreach ($children as $child) {
            if ($child::class !== $related->class) {
                throw new \LogicException(
                    "{$relation->where} relates {$related->class}; give has() a factory of them, not of "
                    . $child::class
                    . '.',
                );
            }
        }
        if ($attribute instanceof BelongsToMany) {
            foreach ($children as $child) {
                $this->saved($child);
            }
            $parent->attach($name, array_map(static fn(Model $child): int|string => self::key(
                $related,
                $child,
                $foreign->property,
            ), $children));
            // @mago-expect analysis:string-member-selector
            $parent->{$name} = $children;
            return;
        }
        $key = self::key($map, $parent, $local->property);
        $morph = $relation->morphType($map);
        foreach ($children as $child) {
            $related->assign($child, $foreign->property, $key);
            if ($morph !== null) {
                $related->assign($child, $related->columns[$morph[0]]->property, $morph[1]);
            }
            $this->saved($child);
        }
        $single = $attribute instanceof HasOne || $attribute instanceof MorphOne;
        // @mago-expect analysis:string-member-selector
        $parent->{$name} = $single ? $children[0] ?? null : $children;
    }

    /**
     * A key a saved model holds, which is an int or a string.
     */
    private static function key(ModelMap $map, Model $model, string $property): int|string
    {
        // @mago-expect analysis:mixed-assignment
        $key = $map->value($model, $property);
        if (!is_int($key) && !is_string($key)) {
            throw new \LogicException($model::class . "::\${$property} holds no key; save the model first.");
        }
        return $key;
    }

    /**
     * One model, not saved.
     *
     * @return T
     */
    public function makeOne(): Model
    {
        return $this->count(1)->make()[0];
    }

    /**
     * One model, saved.
     *
     * @return T
     */
    public function createOne(): Model
    {
        return $this->count(1)->create()[0];
    }
}

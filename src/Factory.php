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
     * The models, not saved.
     *
     * @return list<T>
     */
    public function make(): array
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
        $models = $this->make();
        foreach ($models as $model) {
            $model->save();
        }
        return $models;
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

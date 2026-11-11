<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * The names Tide assumes where a model does not say: its table, and the
 * keys and pivot of its relations. An attribute's own name always wins.
 *
 * @internal
 */
final class Names
{
    /**
     * "App\Models\OrderItem" as "order_item".
     */
    public static function snake(string $class): string
    {
        $short = substr($class, (int) strrpos('\\' . $class, '\\'));
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $short));
    }

    /**
     * "App\Models\OrderItem" as "order_items". A word English does not
     * make plural this way (person, child) is given with #[Table].
     */
    public static function table(string $class): string
    {
        $name = self::snake($class);
        return match (true) {
            preg_match('/[^aeiou]y$/', $name) === 1 => substr($name, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/', $name) === 1 => $name . 'es',
            default => $name . 's',
        };
    }

    /**
     * The column that points at a model: "book_id" for Book.
     */
    public static function key(string $class): string
    {
        return self::snake($class) . '_id';
    }

    /**
     * The table between two models: both names, in alphabetical order, as "book_tag".
     */
    public static function pivot(string $one, string $other): string
    {
        $names = [self::snake($one), self::snake($other)];
        sort($names);
        return implode('_', $names);
    }
}

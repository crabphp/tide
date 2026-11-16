<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * How many related rows a relation has, read by withCount() into this
 * property. It is no column: save() never writes it, and it stays
 * uninitialised until a query that asked for it reads it.
 *
 *     #[Count('comments')]
 *     public int $comments_count;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Count
{
    public function __construct(
        public string $relation,
    ) {
        Table::identifier($relation, self::class . '::$relation');
    }
}

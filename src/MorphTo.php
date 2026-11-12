<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * Belongs to a row of one of several models: the property's union type lists
 * them, the type column says which, by its morph name, and the id column which
 * row. Both columns are named after the property unless named here:
 *
 *     #[MorphTo]
 *     public Post|Video $commentable;    // commentable_type and commentable_id
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class MorphTo
{
    public function __construct(
        public ?string $type = null,
        public ?string $id = null,
    ) {
        if ($type !== null) {
            Table::identifier($type, self::class . '::$type');
        }
        if ($id !== null) {
            Table::identifier($id, self::class . '::$id');
        }
    }
}

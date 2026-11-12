<?php

declare(strict_types=1);

namespace Crab\Tide;

/**
 * The one row of a model whose #[MorphTo] points here, as MorphMany for a
 * single row; the property's type is the related model:
 *
 *     #[MorphOne]
 *     public ?Image $image;
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class MorphOne
{
    /**
     * @param string|null $relation The related model's #[MorphTo] property, when several list this model.
     * @param string|null $localKey This model's column the id column holds; its key when left out.
     */
    public function __construct(
        public ?string $relation = null,
        public ?string $localKey = null,
    ) {
        if ($relation !== null) {
            Table::identifier($relation, self::class . '::$relation');
        }
        if ($localKey !== null) {
            Table::identifier($localKey, self::class . '::$localKey');
        }
    }
}

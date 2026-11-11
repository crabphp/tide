<?php

declare(strict_types=1);

namespace Crab\Tide;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class HasMany
{
    public function __construct(
        public string $related,
        public ?string $foreignKey = null,
        public ?string $localKey = null,
    ) {
        if ($foreignKey !== null) {
            Table::identifier($foreignKey, self::class . '::$foreignKey');
        }
        if ($localKey !== null) {
            Table::identifier($localKey, self::class . '::$localKey');
        }
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class HasOne
{
    public function __construct(
        public string $foreignKey,
        public ?string $localKey = null,
    ) {
        Table::identifier($foreignKey, self::class . '::$foreignKey');
        if ($localKey !== null) {
            Table::identifier($localKey, self::class . '::$localKey');
        }
    }
}

<?php

declare(strict_types=1);

namespace Crab\Tide;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class BelongsTo
{
    public function __construct(
        public ?string $foreignKey = null,
        public ?string $ownerKey = null,
    ) {
        if ($foreignKey !== null) {
            Table::identifier($foreignKey, self::class . '::$foreignKey');
        }
        if ($ownerKey !== null) {
            Table::identifier($ownerKey, self::class . '::$ownerKey');
        }
    }
}

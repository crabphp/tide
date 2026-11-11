<?php

declare(strict_types=1);

namespace Crab\Tide;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class BelongsToMany
{
    public function __construct(
        public string $related,
        public string $pivot,
        public string $key,
        public string $relatedKey,
    ) {
        Table::identifier($pivot, self::class . '::$pivot');
        Table::identifier($key, self::class . '::$key');
        Table::identifier($relatedKey, self::class . '::$relatedKey');
    }
}
